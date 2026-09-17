<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\Corp;
use App\Models\Ice;
use App\Models\Skill;
use App\Models\System;
use App\Support\Text;
use Closure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Instantiates the world: the portal, and enough machines to fill it.
 *
 * Not a Database\Seeders seeder — nothing here sets a database up. It is the
 * grid's supply, the way MarketSeeder is the market's, and it is written to the
 * same shape for the same reasons: a TOP-UP TO A TARGET rather than an
 * addition, so running it twice is not two worlds. What is already standing
 * counts, so a pass over a full grid does nothing at all and a pass over a grid
 * that has been burned down replaces exactly what was lost.
 *
 * The target is `occupancy` percent of the positions, which leaves roughly 30%
 * of the grid EMPTY on purpose. That reserve is what relocation and
 * instantiation move through; a grid with nowhere to churn to is a grid that
 * has stopped being able to move, and the churn is the whole reason the map
 * cannot be finished.
 *
 * Two things are placed rather than rolled:
 *
 *  - THE PORTAL, at the middle, exactly one, and it takes precedence over
 *    whatever is standing there — anything else gets moved aside rather than
 *    overwritten, because a system is a machine with an identity and this is a
 *    generator, not a demolition crew.
 *  - NOTHING ELSE. Every other position is a roll: the type and the owning
 *    corp are both drawn evenly, and between them they name the machine — a
 *    generated system stores no name at all, only the serial that tells it
 *    from the other machines that corp runs. Contents are phase 4, and they
 *    will be rolled here too.
 */
final class GridSeeder
{
    /**
     * Which rung of the operating-system ladder a machine of this grade runs.
     *
     * Nine grades spread across however many rungs there are, so the top grade
     * gets the top rung and the bottom gets the bottom whatever the ladder's
     * length becomes. A fifth operating system is a skill row and nothing else.
     *
     * Static and pure, because the same mapping is what the backfill migration
     * had to do and what a re-seed has to agree with.
     *
     * @param  list<int>  $ladder  Skill ids, cheapest first.
     */
    public static function operatingSystemFor(int $grade, array $ladder): ?int
    {
        if ($ladder === []) {
            return null;
        }

        return $ladder[(int) floor(($grade - Grade::MIN) * count($ladder) / Grade::MAX)] ?? end($ladder);
    }

    /** Rows per insert. Four million systems is not one statement. */
    private const CHUNK = 500;

    /**
     * @param  Closure(int, int): int  $roll  Inclusive both ends, like random_int.
     */
    public function __construct(
        private readonly Grid $grid,
        private readonly SystemNamer $namer,
        private readonly int $occupancy,
        private readonly Closure $roll,
    ) {}

    /** @param  ?Closure(int, int): int  $roll */
    public static function fromConfig(?Closure $roll = null): self
    {
        $roll ??= static fn (int $min, int $max): int => random_int($min, $max);

        return new self(
            Grid::fromConfig(),
            SystemNamer::fromConfig($roll),
            (int) Config::get('cyberspace.occupancy', 70),
            $roll,
        );
    }

    public function run(bool $dryRun = false): GridSeedResult
    {
        $positions = $this->grid->positions();
        $target = (int) round($positions * $this->occupancy / 100);

        [$portalPlaced, $occupantMoved, $portalCreated] = $this->ensurePortal($dryRun);

        // A dry run wrote nothing, so a portal it WOULD have created has to be
        // counted by hand. One it would only have moved is already in the
        // count, and adding it again would under-fill the world by one.
        $occupied = $this->grid->occupied() + ($dryRun && $portalCreated ? 1 : 0);
        $wanted = max(0, $target - $occupied);

        $created = $dryRun ? $wanted : $this->fill($wanted);

        return new GridSeedResult(
            positions: $positions,
            target: $target,
            occupiedBefore: $occupied,
            created: $created,
            portalPlaced: $portalPlaced,
            occupantMoved: $occupantMoved,
            gridFull: $created < $wanted,
        );
    }

    /**
     * There is exactly one portal and it stands in the middle.
     *
     * It is the one system that never moves, so its position is the fixed fact
     * and anything else found there is what has to give way. Moving the
     * occupant rather than deleting it matters even in a generator: that row
     * may be somebody's bookmark, and a machine that vanished is a different
     * story from one that relocated.
     *
     * @return array{bool, bool, bool} [placed, an occupant was moved aside, the row is new]
     */
    private function ensurePortal(bool $dryRun): array
    {
        $portal = System::query()->firstWhere('type', SystemType::Portal->value);
        $where = $this->grid->portal();

        if ($portal !== null && $portal->position()->equals($where)) {
            return [false, false, false];
        }

        $occupant = $this->grid->at($where);
        $moved = $occupant !== null && ! $occupant->isPortal();

        if ($dryRun) {
            return [true, $moved, $portal === null];
        }

        DB::transaction(function () use ($portal, $where, $occupant, $moved): void {
            if ($moved && $occupant !== null) {
                $free = $this->grid->freePosition($this->roll);

                // A grid with no room at all leaves the occupant where it is
                // and the portal unplaced rather than losing one of them. That
                // is a world that needs burning down, not a row to overwrite.
                if ($free === null) {
                    return;
                }

                $occupant->update(['x' => $free->x, 'y' => $free->y]);
            }

            // A portal that exists in the wrong place has MOVED there, keeping
            // its address: it is the same system, and the only case in the
            // game where a relocation does not mint a new address.
            if ($portal !== null) {
                $portal->update(['x' => $where->x, 'y' => $where->y]);

                return;
            }

            System::query()->create([
                'address' => $this->address(),
                'x' => $where->x,
                'y' => $where->y,
                'type' => SystemType::Portal,
                // The one AUTHORED name in a generated world, and no
                // designation: there is exactly one portal, so a serial would
                // be telling it apart from nothing.
                'name' => Text::get('cyberspace.portal.name'),
            ]);
        });

        return [true, $moved, $portal === null];
    }

    /**
     * Put $wanted machines on free positions, and say how many landed.
     *
     * Fewer than asked for means the grid ran out of room, which the caller
     * reports rather than treats as a failure: a world that cannot fit its own
     * target is a tuning problem, not an exception.
     *
     * Room is found by rejection sampling, which is cheap while there is a
     * reserve to sample and degrades as the last few positions fill. That is
     * the right trade at both grid sizes and it is why an occupancy pushed
     * towards 100 may stop short and say so — the design keeps 30% of the grid
     * empty, and a world with no reserve has already stopped being able to
     * churn.
     */
    private function fill(int $wanted): int
    {
        $types = SystemType::ordinary();

        // Every machine has an owner, drawn evenly. Read once for the whole
        // pass rather than per row: the roster is small, fixed, and arrives by
        // migration, so a query per system would be 2 866 round trips to learn
        // the same 121 ids.
        //
        // An empty roster is not an error. It means the corps migration has
        // not run, and a world of unowned machines is better than no world.
        $owners = Corp::query()->pluck('id')->all();

        /*
         * The operating systems, cheapest first.
         *
         * Read as a LADDER rather than as a set, because which one a machine
         * runs follows its grade: the common one at the bottom of the grid and
         * the one almost nobody has trained at the top. Rank already says how
         * far up a skill sits, so nothing about that pairing had to be written
         * down a second time.
         */
        $operatingSystems = Skill::query()
            ->where('category', 'Operating Systems')
            ->orderBy('rank')
            ->pluck('id')
            ->all();

        /*
         * The ICE catalogue, grouped by the grades each piece turns up on.
         *
         * Read once for the whole pass, like the corps and the operating
         * systems above: twelve rows answering the same question 2 866 times
         * is 2 866 round trips to learn twelve ids.
         */
        $iceByGrade = [];

        foreach (Ice::query()->get(['id', 'level', 'grade_min', 'grade_max']) as $ice) {
            foreach (range($ice->grade_min, $ice->grade_max) as $grade) {
                // id => level: the level is what the bounty odds are keyed on.
                $iceByGrade[$grade][$ice->id] = $ice->level;
            }
        }

        $rows = [];
        $created = 0;
        $now = now();

        /*
         * THE WHOLE WORLD IS DECIDED IN MEMORY, THEN WRITTEN IN CHUNKS.
         *
         * What is already standing is read once — one query — and everything
         * afterwards is arithmetic. Nothing asks the database whether a
         * position is free or an address is taken, because the answer is
         * already here and asking costs a round trip to a host on the far side
         * of the internet.
         *
         * The sets must NOT be cleared at a chunk boundary. They were, and
         * that was the bug: after the first five hundred rows the generator
         * forgot everything it had placed and fell back to a query per
         * candidate for the rest of the pass.
         *
         * The trade is memory for round trips, bounded by the size of the
         * grid. That is comfortable while the world is materialised, and the
         * design already says it stops being materialised long before the
         * memory matters — see CYBERSPACE.md, *How the grid is stored*.
         */
        $takenPositions = $this->grid->occupiedPositions();
        $takenAddresses = array_fill_keys(System::query()->pluck('address')->all(), true);

        for ($i = 0; $i < $wanted; $i++) {
            $free = $this->grid->freePositionAmong($takenPositions, $this->roll);

            if ($free === null) {
                break;
            }

            $type = $types[($this->roll)(0, count($types) - 1)];
            $grade = Grade::roll($this->roll);
            $address = $this->address($takenAddresses);

            $takenPositions["{$free->x},{$free->y}"] = true;
            $takenAddresses[$address] = true;

            $rows[] = [
                'address' => $address,
                'x' => $free->x,
                'y' => $free->y,
                'type' => $type->value,
                // Weighted steeply towards the harmless. See config; this is
                // the dial for how dangerous the world feels.
                'grade' => $grade,
                // NO NAME. A generated machine is named for its type and its
                // owner, both of which are on the row already; what is stored
                // is the serial that tells it from its siblings. See
                // App\Cyberspace\SystemNamer.
                'name' => null,
                'designation' => $this->namer->designate(),
                'owner_id' => $owners === [] ? null : $owners[($this->roll)(0, count($owners) - 1)],
                'os_skill_id' => self::operatingSystemFor($grade, $operatingSystems),
                // What it defends itself with, decided here and stored. Type
                // guarantees two countermeasures and grade rolls the third.
                ...Defences::roll($type, $grade, $this->roll, $iceByGrade[$grade] ?? [])->columns(),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) >= self::CHUNK) {
                System::query()->insert($rows);
                $created += count($rows);
                $rows = [];
                // The taken sets deliberately survive the chunk. Clearing them
                // is what made this generator quadratic in round trips.
            }
        }

        if ($rows !== []) {
            System::query()->insert($rows);
            $created += count($rows);
        }

        return $created;
    }

    /**
     * An address nothing is using.
     *
     * Decided against a set held in memory rather than by asking the database
     * — the unique index is the guarantee, and this is only the courtesy that
     * keeps the generator from dying on a collision. Four bytes is 4.3 billion
     * values against a grid of four million, so a collision is rare and two in
     * a row are vanishingly so; the loop gives up rather than spinning.
     *
     * @param  array<string, true>  $taken  Every address already in use or spoken for.
     */
    private function address(array $taken = []): string
    {
        for ($try = 0; $try < 8; $try++) {
            $address = Address::generate();

            if (! isset($taken[$address])) {
                return $address;
            }
        }

        return Address::generate();
    }
}
