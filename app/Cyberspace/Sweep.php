<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Achievements\Achievements;
use App\Achievements\Metric;
use App\Models\Character;
use App\Models\System;
use App\Models\SystemContact;
use Illuminate\Support\Facades\DB;

/**
 * Looking around. The one act that writes both halves of discovery.
 *
 * A character standing somewhere sees a SHAPE of the grid around them (their
 * view range, `ViewShape`), and what that costs them is nothing — this is not a scan. A scan is the expensive act
 * that catalogues what a system RUNS: its grade, its operating system, its ICE.
 * A sweep is only what is visible from where you are standing: that there is a
 * machine there at all, what it calls itself, what kind of thing it is, and the
 * address to jump back to. Do not let the two grow into each other.
 *
 * Terrain and contact are written together here because they are one act, and
 * they behave differently afterwards — see the discovery migration. Terrain
 * accumulates and never un-sets; contact is rewritten inside the window,
 * because "the last thing they saw at each position" is what a contact means
 * and looking again is what makes it the last thing. A position in view with
 * nothing standing on it therefore FORGETS what was there, which is correct and
 * is the moment a player learns the world has moved.
 *
 * IT IS NO LONGER A BLIND REWRITE. `first_seen_at` is the first thing a contact
 * has carried that is worth keeping, so the rewrite now CARRIES IT FORWARD:
 * same position and same address means the same machine, and its first sighting
 * stands however many times it has been looked at. That is the diff this
 * docblock used to say would one day be needed.
 *
 * It is still two statements — a delete and one batched insert — rather than an
 * upsert per cell, because a window is forty-nine positions and this runs on
 * every single step. Forty-nine round trips to a database on the far side of
 * the internet is not a price a walk can pay. When a contact carries something
 * that cannot simply be re-stated from the world — a note, an attached scan —
 * that trade stops being available and this becomes a real upsert.
 */
final class Sweep
{
    public function __construct(
        private readonly Grid $grid,
    ) {}

    public static function fromConfig(): self
    {
        return new self(Grid::fromConfig());
    }

    /**
     * Look around from here, and record what is seen.
     *
     * The window is clipped to the grid, because the edges are edges: a sweep
     * from a corner simply sees fewer positions, and it must not mark ground
     * that does not exist — a bit set outside the grid is a bit that can never
     * be un-set and would count towards a total no walk could reach.
     */
    public function from(Character $character, Position $centre, ?ViewShape $shape = null): SweepResult
    {
        $shape ??= ViewShape::base();
        $reach = $shape->radius();

        $positions = $this->window($centre, $shape);
        // The owner comes along for the snapshot below, so it is eager-loaded
        // rather than fetched once per system standing in the window. The query
        // is the shape's bounding square; what is outside the shape is dropped.
        $standing = $this->grid->within($centre, $reach)
            ->filter(static fn (System $system): bool => $shape->covers($centre, $system->position()))
            // A cloaked fortress shows the face its owner chose to anybody without access.
            ->map(fn (System $system): System => FortressCloak::mask($system, $character))
            ->load('owner')
            ->keyBy(static fn (System $system): string => "{$system->x},{$system->y}");

        $remembered = SystemContact::query()
            ->where('character_id', $character->id)
            ->within($centre, $reach)
            ->get()
            ->filter(static fn (SystemContact $contact): bool => $shape->covers($centre, new Position($contact->x, $contact->y)))
            ->keyBy(static fn (SystemContact $contact): string => "{$contact->x},{$contact->y}");

        // Remembered here, and either gone or replaced by a stranger. Counted
        // before anything is written, because the rewrite below is what
        // destroys the evidence.
        $lost = $remembered->reject(
            static fn (SystemContact $contact): bool => $contact->standingIs($standing->get("{$contact->x},{$contact->y}"))
        )->count();

        $made = $standing->reject(
            static fn (System $system): bool => (bool) $remembered->get("{$system->x},{$system->y}")?->standingIs($system)
        )->count();

        $newlySwept = 0;

        DB::transaction(function () use ($character, $positions, $standing, $remembered, &$newlySwept): void {
            $newlySwept = (new Terrain($character))->sweep($positions);

            // Only what stands inside the shape is forgotten and re-seen. A contact in the shape's bounding square
            // but outside the shape was not looked at, and keeps what it was.
            SystemContact::query()
                ->where('character_id', $character->id)
                ->whereIn('id', $remembered->pluck('id'))
                ->delete();

            if ($standing->isEmpty()) {
                return;
            }

            $now = now();

            SystemContact::query()->insert($standing->map(
                static function (System $system) use ($character, $remembered, $now): array {
                    $before = $remembered->get("{$system->x},{$system->y}");

                    return [
                        'character_id' => $character->id,
                        'x' => $system->x,
                        'y' => $system->y,
                        'address' => $system->address,
                        // The name as it READS, not the column: a generated
                        // system has no name of its own, and what the player
                        // saw was the sentence its type and its owner spell.
                        // Composed once here, so a memory is a memory even
                        // after the entity is renamed.
                        'name' => $system->displayName(),
                        'type' => $system->type->value,
                        // The owner's NAME, not its id: a contact is a snapshot
                        // of what was seen, and a pointer would make the memory
                        // change when the entity row did.
                        'owner' => $system->owner?->name,
                        // WHAT IT LOOKED LIKE WHEN SEEN. A contact is a
                        // sighting and is allowed to be wrong: a machine that
                        // shut after you walked past still looks fine on your
                        // map until you sweep the position again, which is the
                        // same rule its address and its owner already follow.
                        'locked_down_until' => $system->isLockedDown() ? $system->locked_down_until : null,
                        'seen_at' => $now,
                        // THE CLOCK IS CARRIED, NOT RESET. Same position and
                        // same address means the same machine, however many
                        // times it has been looked at — so its first sighting
                        // stands. A different address is a stranger who has
                        // moved in, and the clock starts again.
                        'first_seen_at' => $before?->standingIs($system) === true
                            ? $before->first_seen_at
                            : $now,
                    ];
                }
            )->values()->all());
        });

        // GROUND, not systems — the same number the terrain bitmap just grew
        // by, so ground somebody walked twice is counted once and empty ground
        // counts as much as occupied. Zero is the ordinary case and records
        // nothing.
        (new Achievements)->record($character, Metric::GridExplored, $newlySwept);

        return new SweepResult(
            from: $centre,
            positions: count($positions),
            newlySwept: $newlySwept,
            systemsSeen: $standing->count(),
            contactsMade: $made,
            contactsLost: $lost,
        );
    }

    /**
     * Every position inside the shape that is actually on the grid.
     *
     * @return list<Position>
     */
    private function window(Position $centre, ViewShape $shape): array
    {
        $positions = [];

        foreach ($shape->offsets as [$dx, $dy]) {
            $position = new Position($centre->x + $dx, $centre->y + $dy);

            if ($this->grid->contains($position)) {
                $positions[] = $position;
            }
        }

        return $positions;
    }
}
