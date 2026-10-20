<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Characters\Fatigue;
use App\City\ActivityKind;
use App\City\Encounters;
use App\City\EncounterStart;
use App\Decks\DeckTotals;
use App\Models\Bookmark;
use App\Models\Character;
use App\Models\CharacterEncounter;
use App\Models\CityActivity;
use App\Stats\Resolver;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Going in, moving about, and coming back out. The ONE writer of
 * `characters.grid_x` and `grid_y`.
 *
 * Four acts and one rule between them: a character is in at most one place,
 * and having a place IS being in cyberspace. There is no session row to keep
 * in step with a flag, so nothing here has to promise that two records agree
 * — see the migration.
 *
 * ARRIVING ANYWHERE MEANS LOOKING AROUND. Jacking in, stepping and jumping all
 * sweep, because a player who moved and did not see is a player whose map went
 * stale while they were standing in front of it. That is why every arrival
 * hands back a SweepResult and why the two are written in one transaction: a
 * move that recorded a position but not what it revealed would be a position
 * the map cannot explain.
 *
 * ARRIVING ANYWHERE ALSO ROLLS FOR A HUNTER, after the sweep and deliberately
 * outside its transaction — `App\Cyberspace\Hunters::spawn()` is its own
 * complete write (the row, the lock, the loadout) and a hunter's own
 * transaction failing must never take the sweep that already happened back.
 * Heat carries no ceiling, so this is the one thing every arrival risks that
 * jacking in used to risk nothing.
 *
 * READINESS IS THE GATE AND IT IS CHECKED HERE. `DeckTotals::isReady()` is the
 * whole of it — compute, memory, link, power, and draw within supply — and
 * there is no second list. CYBERSPACE.md names one more caller, the start of
 * every intrusion run, which does not exist yet.
 *
 * What this deliberately does NOT do is lock the deck. CYBERSPACE.md wants
 * fitting, stripping, unequipping and destroying refused while a character is
 * jacked in, and those are rules belonging to ModuleFitter, DeckEquipper and
 * the destroy path — three writers enforcing one rule, each in its own place.
 * `Character::isJackedIn()` is what they will ask. Nothing asks yet.
 */
final class Navigator
{
    public function __construct(
        private readonly Grid $grid,
        private readonly Sweep $sweep,
        private readonly Resolver $resolver,
        private readonly Hunters $hunters,
        private readonly Fatigue $fatigue = new Fatigue,
    ) {}

    /** @param  ?Closure(int, int): int  $roll  Threaded to the hunter roll, so a test can pin it. */
    public static function fromConfig(Resolver $resolver, ?Closure $roll = null): self
    {
        return new self(Grid::fromConfig(), Sweep::fromConfig(), $resolver, Hunters::fromConfig($resolver, $roll));
    }

    /**
     * Into cyberspace, at the portal.
     *
     * THE MAIN GRID'S ARRIVAL IS ALWAYS THE PORTAL. A position is not resumed on the
     * way back in, because the design's answer to "how do I get back to the frontier"
     * is a bookmark rather than a saved coordinate — which is what keeps a bookmark
     * worth having. A SUBNET IS THE ONE EXCEPTION: leaving it by jacking out keeps
     * the spot (`resume_x`, `resume_y`, on the grid `grid_id` names) and the next
     * jack-in is there, since getting back in would mean passing the gateway's
     * entry rules again. A flatline clears it (`WaitingRoom::checkFloor()`).
     */
    public function jackIn(Character $character): TravelResult
    {
        if ($character->isJackedIn()) {
            return TravelResult::refused(TravelRefusal::AlreadyJackedIn);
        }

        if (($refusal = $this->jackInRefusal($character)) !== null) {
            return TravelResult::refused($refusal);
        }

        // A subnet position held over from the last jack-out, if it is still a place; otherwise the main portal.
        $resume = $this->resumePoint($character);

        // The portal is where everybody starts and jacking in is not a move, so nothing meets them there.
        return $this->arrive($character, $resume ?? $this->grid->portal(), mayMeet: false);
    }

    /** What stops a body that is not jacked in from jacking in: a fight, a City activity, no deck, a deck that will not run. */
    private function jackInRefusal(Character $character): ?TravelRefusal
    {
        // A fight owns the character until it is settled, whatever they asked for.
        if ($character->current_fight_id !== null) {
            return TravelRefusal::Fighting;
        }

        // No City activity overlaps a jack-in. Surgery has its own words (a body on the
        // table cannot be cancelled out of); a trip or an exploring pass can be, so the
        // refusal tells the player to finish or cancel it. This also means a pass can
        // never mature under somebody in cyberspace, and so no encounter opens there.
        $kind = CityActivity::query()->whereKey($character->getKey())->value('kind');

        if ($kind !== null) {
            return ($kind instanceof ActivityKind ? $kind : ActivityKind::from($kind)) === ActivityKind::Surgery
                ? TravelRefusal::UnderTheKnife
                : TravelRefusal::BusyInTheCity;
        }

        $deck = $character->equippedDeck;

        if ($deck === null) {
            return TravelRefusal::NoDeck;
        }

        if (! DeckTotals::forCharacter($character, $deck, $this->resolver)->isReady()) {
            return TravelRefusal::DeckNotReady;
        }

        return null;
    }

    /**
     * Put somebody on a position by a Secure Tunnel (`FortressTunnel`): jacked in already, they simply arrive; not jacked in,
     * they must be able to (the same refusals as jacking in) and jack in there. Nothing meets them on the way.
     */
    public function tunnelTo(Character $character, Position $position): TravelResult
    {
        if (! $character->isJackedIn() && ($refusal = $this->jackInRefusal($character)) !== null) {
            return TravelResult::refused($refusal);
        }

        return $this->arrive($character, $position, mayMeet: false);
    }

    /**
     * Back out, and the position goes with it.
     *
     * ORDINARILY NOTHING ELSE IS CHECKED: leaving is always allowed. A live
     * RUN is deliberately not one of the things that refuses this — extraction
     * is its own act, belonging to `RunController::egress()`, and a run in
     * progress is guarded by `EnsureRunIsFinished` before a request ever
     * reaches here. A live HUNT is the one thing that does refuse it: there is
     * no door out of cyberspace combat until it is settled, the same way
     * `EnsureHuntIsResolved` already stops the request from arriving.
     */
    public function jackOut(Character $character): TravelResult
    {
        if (! $character->isJackedIn()) {
            return TravelResult::refused(TravelRefusal::NotJackedIn);
        }

        if ($character->current_hunt_id !== null) {
            return TravelResult::refused(TravelRefusal::Hunted);
        }

        // BEFORE the position goes: the snapshot is of where they were and why (see Trail).
        Trail::note('jack_out', $character);

        // On a subnet the spot is kept for the next jack-in; the main grid keeps nothing.
        $character->forceFill([
            'resume_x' => (int) $character->grid_id === 1 ? null : $character->grid_x,
            'resume_y' => (int) $character->grid_id === 1 ? null : $character->grid_y,
            'grid_x' => null,
            'grid_y' => null,
        ])->save();

        return TravelResult::left();
    }

    /**
     * Throw a runner out of a subnet: they arrive on the main-grid gateway linked to the subnet's entry gateway, still jacked in.
     * False when there is no such gateway (nothing moved).
     */
    public function expel(Character $character): bool
    {
        $inside = $this->gridOf($character);
        $gateway = $inside->at($inside->portal());
        $outer = $gateway?->type->isGateway() ? $gateway->linksTo : null;

        if ($outer === null) {
            return false;
        }

        $this->arrive($character, $outer->position(), mayMeet: false);

        return true;
    }

    /**
     * One position, in any of the eight directions.
     *
     * Walking the open grid costs nothing — no turn, no time, no roll. What
     * paces exploration is patience rather than a budget, and the thing that
     * makes a system dangerous is going INTO it, which is not this.
     *
     * A system does not block the position it stands on. Walking onto one is
     * how you look at it.
     */
    public function step(Character $character, Position $to): TravelResult
    {
        $from = $character->gridPosition();

        if ($from === null) {
            return TravelResult::refused(TravelRefusal::NotJackedIn);
        }

        // A step is on the grid you stand on, whatever grid the caller's coordinates were written for.
        $to = new Position($to->x, $to->y, $from->grid);

        // The edge before adjacency, because the two are different sentences
        // and a player walking west from the west edge deserves the one about
        // there being nothing that way.
        if (! $this->gridOf($character)->contains($to)) {
            return TravelResult::refused(TravelRefusal::OffTheGrid);
        }

        if (! $from->isAdjacentTo($to)) {
            return TravelResult::refused(TravelRefusal::NotAStep);
        }

        $result = $this->arrive($character, $to);

        // Walking tires. Jumping and recalling are free, like everything else about them.
        if ($result->succeeded()) {
            $this->fatigue->charge($character, 'grid_step');
        }

        return $result;
    }

    /**
     * Straight to whatever answers to an address.
     *
     * TRADING ADDRESSES IS FINE AND EXPECTED — it is the same act as inviting
     * somebody onto a shared run, and at four million positions the constraint
     * on exploration was never secrecy. An address carries no position, so
     * having one tells you where to arrive and nothing about where it is.
     *
     * Arriving nowhere is an ORDINARY OUTCOME. Addresses die with the systems
     * that wore them, so a jump to one from last week is allowed to resolve to
     * nothing at all; that is the world having moved on rather than a fault,
     * and the two refusals tell "that is not an address" apart from "nothing
     * answers to it".
     */
    public function jump(Character $character, string $address): TravelResult
    {
        if (! $character->isJackedIn()) {
            return TravelResult::refused(TravelRefusal::NotJackedIn);
        }

        if (Address::normalise($address) === null) {
            return TravelResult::refused(TravelRefusal::NotAnAddress);
        }

        // Only what answers on the grid you are on: another grid's address is "nothing answers to it", and the
        // way between grids is a gateway, with its rules, never a jump.
        $system = $this->gridOf($character)->find($address);

        if ($system === null) {
            return TravelResult::refused(TravelRefusal::NoSuchAddress);
        }

        return $this->arrive($character, $system->position());
    }

    /**
     * Go back to a saved place.
     *
     * The other half of *you walk, you bookmark the frontier, you come back* —
     * and the reason a waypoint is worth setting at all. **Returning to one is
     * free**: no travel cost, no turn, and no roll over whether you ARRIVE.
     * Walking is what buys new ground, and a waypoint only saves re-walking
     * ground already bought, so charging for it would tax the player for
     * having explored rather than for exploring. The one roll every arrival
     * still carries — whether a hunter is there when you land — is not an
     * exception: heat does not know how you got somewhere.
     *
     * The two kinds arrive differently, which is the whole of the difference
     * between them:
     *
     *  - A WAYPOINT is a position, so it always works. It was swept once, and
     *    swept ground never stops being swept.
     *  - A SYSTEM BOOKMARK is an address, so it goes through the same lookup a
     *    typed one does and is allowed to arrive nowhere.
     */
    public function recall(Character $character, Bookmark $bookmark): TravelResult
    {
        if ($bookmark->character_id !== $character->id) {
            return TravelResult::refused(TravelRefusal::NotYours);
        }

        if (! $character->isJackedIn()) {
            return TravelResult::refused(TravelRefusal::NotJackedIn);
        }

        $position = $bookmark->position();

        if ($position === null) {
            return $this->jump($character, $bookmark->address);
        }

        // A grid that has shrunk under a waypoint is the only way this fails,
        // and it is a world-configuration change rather than anything the
        // player did.
        return $position->grid === (int) $character->grid_id && $this->gridOf($character)->contains($position)
            ? $this->arrive($character, $position)
            : TravelResult::refused(TravelRefusal::OffTheGrid);
    }

    /**
     * Put somebody on a position for a story (an encounter's `grid_move`), and let them look around.
     *
     * No hunter roll and no encounter: a move an encounter makes must not open another. Refused for a position off
     * the grid and for somebody who is not jacked in.
     */
    public function relocate(Character $character, Position $to): bool
    {
        if (! $character->isJackedIn() || $to->grid !== (int) $character->grid_id || ! $this->gridOf($character)->contains($to)) {
            return false;
        }

        DB::transaction(function () use ($character, $to): void {
            $character->forceFill(['grid_x' => $to->x, 'grid_y' => $to->y])->save();
            $this->sweep->from($character, $to, ViewShape::forCharacter($character, $this->resolver));
        });

        return true;
    }

    /**
     * Through the gateway under their feet, to its other end.
     *
     * A gateway is one end of a pair (`systems.links_to_system_id`) and passing is an arrival at the other, which is on
     * the other grid: into a subnet from the main grid, back out of it from the subnet's. Nothing meets them on the way
     * (the hunter's roll still happens, as on any arrival). A gateway with entry rules (`EntryRules`) lets through only
     * those who meet one; the subnet's own end carries none.
     */
    public function pass(Character $character): TravelResult
    {
        $here = $character->gridPosition();

        if ($here === null) {
            return TravelResult::refused(TravelRefusal::NotJackedIn);
        }

        $gateway = $this->gridOf($character)->at($here);
        $other = $gateway?->type->isGateway() ? $gateway->linksTo : null;

        if ($other === null) {
            return TravelResult::refused(TravelRefusal::NoGateway);
        }

        if (! EntryRules::allow($character, $gateway)) {
            return TravelResult::refused(TravelRefusal::GatewayClosed);
        }

        return $this->arrive($character, $other->position(), mayMeet: false);
    }

    /**
     * Where a jack-in resumes on a subnet, or null for the main portal: nothing kept, or what was kept is no longer a place.
     */
    private function resumePoint(Character $character): ?Position
    {
        if ((int) $character->grid_id === 1 || $character->resume_x === null || $character->resume_y === null) {
            return null;
        }

        $position = new Position((int) $character->resume_x, (int) $character->resume_y, (int) $character->grid_id);

        try {
            return Grid::forId($position->grid)->contains($position) ? $position : null;
        } catch (\RuntimeException) {
            return null;
        }
    }

    /** The grid this character is on: the one held, or the subnet's. */
    private function gridOf(Character $character): Grid
    {
        $id = (int) ($character->grid_id ?? 1);

        return $this->grid->id() === $id ? $this->grid : Grid::forId($id);
    }

    /**
     * Stand here, and look around.
     *
     * One transaction, because a position written without the sweep that
     * explains it is a map with a hole where the player is standing.
     */
    private function arrive(Character $character, Position $position, bool $mayMeet = true): TravelResult
    {
        $sweep = DB::transaction(function () use ($character, $position): SweepResult {
            // Jacked in, so there is nothing to resume: the spot is where they stand.
            $character->forceFill([
                'grid_id' => $position->grid,
                'grid_x' => $position->x,
                'grid_y' => $position->y,
                'resume_x' => null,
                'resume_y' => null,
            ])->save();

            // What they see from here is their resolved view range, asked once per arrival.
            return $this->sweep->from($character, $position, ViewShape::forCharacter($character, $this->resolver));
        });

        // OUTSIDE the sweep's transaction and deliberately so — spawning a
        // hunter is its own complete write (the row, the lock, the loadout),
        // and it failing must never take back an arrival that already
        // happened.
        // A Warden within reach that notices them attacks first; otherwise heat may draw a hunter.
        $hunt = app(Wardens::class)->ambush($character) ?? ($this->hunters->rolls($character) ? $this->hunters->spawn($character) : null);

        // THEN AN ENCOUNTER, by the same rule and in its own write: a hunt that began makes the character
        // ineligible, so the two never overlap. On the LOCKED row, because two arrivals at once must not both
        // find nothing open.
        $encounter = null;

        if ($mayMeet && $hunt === null) {
            $encounter = DB::transaction(
                fn (): ?CharacterEncounter => app(Encounters::class)->maybeOpen(
                    Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail(),
                    EncounterStart::GridStep,
                ),
            );

            // Standing on a system, the system door: same lock, same roll, its own chance and filters.
            if ($encounter === null && ($system = $this->gridOf($character)->at($position)) !== null) {
                $encounter = DB::transaction(
                    fn (): ?CharacterEncounter => app(Encounters::class)->maybeOpenFor(
                        Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail(),
                        EncounterStart::System,
                        $system,
                    ),
                );
            }

            if ($encounter !== null) {
                $character->refresh();
            }
        }

        return TravelResult::arrived($position, $sweep, $hunt, $encounter);
    }
}
