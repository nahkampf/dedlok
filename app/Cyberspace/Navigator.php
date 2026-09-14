<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Decks\DeckTotals;
use App\Models\Bookmark;
use App\Models\Character;
use App\Stats\Resolver;
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
    ) {}

    public static function fromConfig(Resolver $resolver): self
    {
        return new self(Grid::fromConfig(), Sweep::fromConfig(), $resolver);
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

        $deck = $character->equippedDeck;

        if ($deck === null) {
            return TravelResult::refused(TravelRefusal::NoDeck);
        }

        if (! DeckTotals::forCharacter($character, $deck, $this->resolver)->isReady()) {
            return TravelResult::refused(TravelRefusal::DeckNotReady);
        }

        return $this->arrive($character, $this->grid->portal());
    }

    /**
     * Back out, and the position goes with it.
     *
     * Nothing is checked and nothing can refuse this beyond not being in:
     * leaving is always allowed. Extraction — leaving in the middle of an
     * intrusion, which can fail — is a different act belonging to a run that
     * does not exist yet, and it must not be folded into this one.
     */
    public function jackOut(Character $character): TravelResult
    {
        if (! $character->isJackedIn()) {
            return TravelResult::refused(TravelRefusal::NotJackedIn);
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
     * free**: no cost, no roll, no turn. Walking is what buys new ground, and a
     * waypoint only saves re-walking ground already bought, so charging for it
     * would tax the player for having explored rather than for exploring.
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
    private function arrive(Character $character, Position $position): TravelResult
    {
        $sweep = DB::transaction(function () use ($character, $position): SweepResult {
            $character->forceFill(['grid_x' => $position->x, 'grid_y' => $position->y])->save();

            return $this->sweep->from($character, $position);
        });

        return TravelResult::arrived($position, $sweep);
    }
}
