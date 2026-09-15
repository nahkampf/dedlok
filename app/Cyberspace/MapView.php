<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\Bookmark;
use App\Models\Character;
use App\Models\SystemContact;
use App\Models\SystemScan;
use Illuminate\Support\Facades\Config;

/**
 * The map as the screen needs it: rows of cells, already answered.
 *
 * THE MAP IS A MEMORY, NOT A FEED. Everything here is read out of what the
 * character has swept and what they last saw — never out of `systems`. A map
 * drawn from the live table would quietly show a player machines they have
 * never met and moves they could not have known about, and would make the
 * whole relocation mechanic invisible.
 *
 * It works for a character who is NOT jacked in, centred on the portal, with
 * no player cell and nothing steppable. That is what lets the map be the way
 * IN as well as the thing you use once you are there — a screen that appeared
 * only after you were already inside would be a screen nobody could find.
 *
 * Three queries, whatever the size of the window: the terrain chunks it
 * touches, the contacts inside it, and the waypoints inside it. The cells are
 * then assembled in memory, because four hundred cells asking one question
 * each is four hundred queries.
 */
final class MapView
{
    /**
     * @param  list<list<MapCell>>  $rows
     */
    private function __construct(
        public readonly array $rows,
        public readonly Position $centre,
        public readonly int $range,
        public readonly bool $jackedIn,
    ) {}

    public static function for(Character $character, ?Grid $grid = null, ?int $range = null): self
    {
        $grid ??= Grid::fromConfig();
        $range ??= (int) Config::get('cyberspace.map_range', 10);

        // Standing somewhere, or looking at the world from outside it. The
        // portal is the right centre for the second case: it is where jacking
        // in would put them.
        $standing = $character->gridPosition();
        $centre = $standing ?? $grid->portal();

        $contacts = SystemContact::query()
            ->where('character_id', $character->id)
            ->within($centre, $range)
            ->get()
            ->keyBy(static fn (SystemContact $contact): string => "{$contact->x},{$contact->y}");

        $waypoints = Bookmark::query()
            ->where('character_id', $character->id)
            ->ofKind(BookmarkKind::Waypoint)
            ->whereBetween('x', [$centre->x - $range, $centre->x + $range])
            ->whereBetween('y', [$centre->y - $range, $centre->y + $range])
            ->get()
            ->keyBy(static fn (Bookmark $mark): string => "{$mark->x},{$mark->y}");

        /*
         * What they have found out, by ADDRESS.
         *
         * Keyed on the address rather than the position because that is what a
         * scan describes: a machine that relocated since is a file about a
         * machine that is no longer there, and keying by position would show
         * its grade against whatever moved in. The contact supplies the
         * address, so a cell only shows a grade when the file and the memory
         * agree about which machine they are talking about.
         *
         * Live only — spent or stale files say nothing, which is what makes
         * scan data perishable rather than a permanent unlock.
         */
        $scans = SystemScan::query()
            ->where('character_id', $character->id)
            ->live()
            ->whereNotNull('grade')
            ->pluck('grade', 'address');

        // Read once rather than per cell: four hundred cells asking config the
        // same question is four hundred answers that were always going to be
        // the same.
        $newFor = (int) Config::get('cyberspace.new_for_minutes', 10);

        // How far they can see from where they stand. The drawn map is wider
        // than this on purpose — what is outside it is memory rather than
        // sight, and the cells say so by stepping back.
        $sight = $grid->viewRange();

        $terrain = new Terrain($character);
        $rows = [];

        for ($y = $centre->y - $range; $y <= $centre->y + $range; $y++) {
            $row = [];

            for ($x = $centre->x - $range; $x <= $centre->x + $range; $x++) {
                $position = new Position($x, $y);
                $key = "{$x},{$y}";
                $onGrid = $grid->contains($position);

                $row[] = new MapCell(
                    position: $position,
                    onGrid: $onGrid,
                    // Terrain is only asked about real positions: a bit
                    // outside the grid cannot have been set, and asking would
                    // mean locating a negative one.
                    swept: $onGrid && $terrain->hasSwept($position),
                    contact: $contacts->get($key),
                    bookmark: $waypoints->get($key),
                    isYou: $standing !== null && $standing->equals($position),
                    isStep: $standing !== null && $grid->isStep($standing, $position),
                    // Only a machine can be new. Ground cannot: a position
                    // never changes, which is the whole reason terrain never
                    // goes stale.
                    isNew: $contacts->get($key)?->isNew($newFor) ?? false,
                    // Only what a scan found out. There is nowhere else on
                    // this screen a grade could come from, which is how the
                    // rule that the map never shows an unscanned one is kept.
                    grade: ($address = $contacts->get($key)?->address) === null
                        ? null
                        : ($scans[$address] ?? null),
                    // Nothing is lit for somebody who is not in there: a
                    // jacked-out player is reading the whole map from memory.
                    inView: $standing !== null
                        && $onGrid
                        && $standing->distanceTo($position) <= $sight,
                );
            }

            $rows[] = $row;
        }

        return new self($rows, $centre, $range, $standing !== null);
    }

    /** Cells across, which is what the CSS needs to lay out the columns. */
    public function span(): int
    {
        return $this->range * 2 + 1;
    }

    /** How many positions this character has swept altogether, for the readout. */
    public static function sweptCount(Character $character): int
    {
        return (new Terrain($character))->sweptCount();
    }
}
