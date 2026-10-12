<?php

declare(strict_types=1);

namespace App\Cyberspace;

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
    ) {}

    /** @param  ?Closure(int, int): int  $roll  Threaded to the hunter roll, so a test can pin it. */
    public static function fromConfig(Resolver $resolver, ?Closure $roll = null): self
    {
        return new self(Grid::fromConfig(), Sweep::fromConfig(), $resolver, Hunters::fromConfig($resolver, $roll));
    }

    /**
     * Into cyberspace, at the portal.
     *
     * EVERY ARRIVAL IS THE PORTAL. A position is not resumed on the way back
     * in, because the design's answer to "how do I get back to the frontier"
     * is a bookmark rather than a saved coordinate — which is what keeps a
     * bookmark worth having.
     */
    public function jackIn(Character $character): TravelResult
    {
        if ($character->isJackedIn()) {
            return TravelResult::refused(TravelRefusal::AlreadyJackedIn);
        }

        // A fight owns the character until it is settled, whatever they asked for.
        if ($character->current_fight_id !== null) {
            return TravelResult::refused(TravelRefusal::Fighting);
        }

        // No City activity overlaps a jack-in. Surgery has its own words (a body on the
        // table cannot be cancelled out of); a trip or an exploring pass can be, so the
        // refusal tells the player to finish or cancel it. This also means a pass can
        // never mature under somebody in cyberspace, and so no encounter opens there.
        $kind = CityActivity::query()->whereKey($character->getKey())->value('kind');

        if ($kind !== null) {
            return TravelResult::refused(
                ($kind instanceof ActivityKind ? $kind : ActivityKind::from($kind)) === ActivityKind::Surgery
                    ? TravelRefusal::UnderTheKnife
                    : TravelRefusal::BusyInTheCity,
            );
        }

        $deck = $character->equippedDeck;

        if ($deck === null) {
            return TravelResult::refused(TravelRefusal::NoDeck);
        }

        if (! DeckTotals::forCharacter($character, $deck, $this->resolver)->isReady()) {
            return TravelResult::refused(TravelRefusal::DeckNotReady);
        }

        // The portal is where everybody starts and jacking in is not a move, so nothing meets them there.
        return $this->arrive($character, $this->grid->portal(), mayMeet: false);
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

        $character->forceFill(['grid_x' => null, 'grid_y' => null])->save();

        return TravelResult::left();
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

        // The edge before adjacency, because the two are different sentences
        // and a player walking west from the west edge deserves the one about
        // there being nothing that way.
        if (! $this->grid->contains($to)) {
            return TravelResult::refused(TravelRefusal::OffTheGrid);
        }

        if (! $from->isAdjacentTo($to)) {
            return TravelResult::refused(TravelRefusal::NotAStep);
        }

        return $this->arrive($character, $to);
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

        $system = $this->grid->find($address);

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
        return $this->grid->contains($position)
            ? $this->arrive($character, $position)
            : TravelResult::refused(TravelRefusal::OffTheGrid);
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
            $character->forceFill(['grid_x' => $position->x, 'grid_y' => $position->y])->save();

            // What they see from here is their resolved view range, asked once per arrival.
            return $this->sweep->from($character, $position, ViewShape::forCharacter($character, $this->resolver));
        });

        // OUTSIDE the sweep's transaction and deliberately so — spawning a
        // hunter is its own complete write (the row, the lock, the loadout),
        // and it failing must never take back an arrival that already
        // happened.
        $hunt = $this->hunters->rolls($character) ? $this->hunters->spawn($character) : null;

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

            if ($encounter !== null) {
                $character->refresh();
            }
        }

        return TravelResult::arrived($position, $sweep, $hunt, $encounter);
    }
}
