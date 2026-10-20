<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\Character;
use App\Models\System;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * The world, and the ONE place that knows how it is stored.
 *
 * Everything anybody asks about cyberspace is the same question — *what is at
 * (x, y)?* — and the answer is a table today and a generator with override rows
 * at 2048 x 2048, where 4.2 million materialised systems stop being a table.
 * Both have to work, so the question is answered here from the start and the
 * storage swaps underneath. NOTHING ELSE IN THE CODEBASE MAY QUERY `systems` BY
 * POSITION; the day the generator lands, this class changes and nothing else
 * does.
 *
 * What it deliberately does NOT do is decide anything about a character: what
 * they can see, where they have been, whether they may move. Those need a
 * character and this needs only a world, which is what keeps it usable by the
 * seeder, by housekeeping and by a test with no player in it.
 */
final class Grid
{
    public function __construct(
        private readonly int $size,
        private readonly Position $portal,
        private readonly int $viewRange,
        /** Which grid this is (`grids.id`). 1 is the main grid; `fromConfig()` is always it. */
        private readonly int $id = 1,
        /** A subnet's address prefix (`mil`); null on the main grid, whose addresses carry none. */
        private readonly ?string $prefix = null,
    ) {}

    public static function fromConfig(): self
    {
        $size = (int) Config::get('cyberspace.size', 128);

        return new self(
            $size,
            // 64 has no true centre cell and it does not matter: any single
            // cell near the middle is the portal. Derived rather than
            // configured, so the two cannot disagree when the grid grows.
            new Position(intdiv($size, 2), intdiv($size, 2)),
            (int) Config::get('cyberspace.view_range', 3),
        );
    }

    public function prefix(): ?string
    {
        return $this->prefix;
    }

    /** `grids.id`: 1 for the main grid. */
    public function id(): int
    {
        return $this->id;
    }

    /**
     * Grid number `$id`: the main one from the settings, a subnet from its row (entered at its entry point).
     *
     * @throws \RuntimeException for a grid that is not there
     */
    public static function forId(int $id): self
    {
        if ($id === 1) {
            return self::fromConfig();
        }

        $row = DB::table('grids')->find($id) ?? throw new \RuntimeException("There is no grid {$id}.");

        return new self((int) $row->size, new Position((int) $row->entry_x, (int) $row->entry_y, $id), (int) Config::get('cyberspace.view_range', 3), $id, $row->prefix);
    }

    /** The grid a character stands on, or last stood on. */
    public static function of(Character $character): self
    {
        return self::forId((int) ($character->grid_id ?? 1));
    }

    /** The system answering to this address on ANY grid (an admin typing an address knows only the address). */
    public static function findAnywhere(string $address): ?System
    {
        $address = Address::normalise($address);

        return $address === null ? null : System::query()->firstWhere('address', $address);
    }

    public function size(): int
    {
        return $this->size;
    }

    /** How many places there are. Four million at full size. */
    public function positions(): int
    {
        return $this->size * $this->size;
    }

    public function viewRange(): int
    {
        return $this->viewRange;
    }

    /**
     * Where every player arrives on jacking in.
     *
     * A position rather than the system standing on it: the portal is the one
     * system that never moves, so its coordinates are the fixed fact and the
     * row is what has to agree with them.
     */
    public function portal(): Position
    {
        return $this->portal;
    }

    /**
     * Is this a place at all?
     *
     * THE GRID DOES NOT WRAP. The edges are edges: a step off one is not a step
     * to the far side, it is not a step. A viewing window at the edge simply
     * sees fewer positions, and the corners are the quietest part of the map.
     */
    public function contains(Position $position): bool
    {
        return $position->x >= 0 && $position->x < $this->size
            && $position->y >= 0 && $position->y < $this->size;
    }

    /** What is standing here, or null — which is 30% of the grid and not an error. */
    public function at(Position $position): ?System
    {
        return System::query()->at($position)->first();
    }

    public function isEmpty(Position $position): bool
    {
        return $this->at($position) === null;
    }

    /**
     * The system answering to this address, or null.
     *
     * Null is the ordinary answer and not a failure: addresses die with the
     * systems that wore them, so a jump to one somebody traded you last week is
     * allowed to arrive nowhere. That is the world having moved on.
     */
    public function find(string $address): ?System
    {
        $address = Address::normalise($address);

        return $address === null ? null : System::query()->where('grid_id', $this->id)->firstWhere('address', $address);
    }

    /**
     * Everything inside a square window centred here, edges clipped.
     *
     * @return Collection<int, System>
     */
    public function within(Position $centre, ?int $range = null): Collection
    {
        return System::query()->within($centre, $range ?? $this->viewRange)->get();
    }

    /**
     * May this position be stepped to from that one?
     *
     * Adjacency and the edge, and nothing else — no cost, no obstruction, no
     * system in the way. A system does not block a position: walking onto one
     * is how you look at it.
     */
    public function isStep(Position $from, Position $to): bool
    {
        return $this->contains($to) && $from->isAdjacentTo($to);
    }

    /**
     * A position with nothing on it, or null if the grid is full.
     *
     * Rejection sampling: pick a place, take it if it is free. With 30% of the
     * grid empty by design that is a little over three tries on average, and it
     * stays a little over three tries when the grid is four million positions —
     * which is the property that matters, because the alternative (list the
     * free positions, pick one) is the one that has to hold a million rows in
     * memory to answer.
     *
     * The cap is there so a full grid ends the loop rather than the request.
     * A grid with no room is a world that has stopped being able to churn, and
     * the honest answer to "where does this relocate to" is then null.
     *
     * `$taken` is for a caller building a BATCH: positions it has already
     * spoken for and not yet written. The table cannot know about those, and a
     * generator that asked twice and got the same answer would hand the
     * database two rows for one position.
     *
     * @param  Closure(int, int): int  $roll
     * @param  array<string, true>  $taken  Keyed "x,y".
     */
    public function freePosition(Closure $roll, array $taken = [], int $attempts = 200): ?Position
    {
        for ($try = 0; $try < $attempts; $try++) {
            $position = new Position($roll(0, $this->size - 1), $roll(0, $this->size - 1), $this->id);

            if (isset($taken["{$position->x},{$position->y}"])) {
                continue;
            }

            if ($this->isEmpty($position)) {
                return $position;
            }
        }

        return null;
    }

    /**
     * Every occupied position, as a set keyed "x,y".
     *
     * ONE QUERY FOR THE WHOLE GRID, for a caller that is about to place many
     * systems and would otherwise ask the database once per candidate. At the
     * current size that is 4 096 keys at most; the trade is memory for round
     * trips, and round trips are what hurt against a database on the far side
     * of the internet.
     *
     * It is here rather than in the seeder because this class is the one place
     * that knows how the world is stored — the day the grid becomes a
     * generator with override rows, this is what changes.
     *
     * @return array<string, true>
     */
    public function occupiedPositions(): array
    {
        $taken = [];

        foreach (System::query()->where('grid_id', $this->id)->select(['x', 'y'])->cursor() as $system) {
            $taken["{$system->x},{$system->y}"] = true;
        }

        return $taken;
    }

    /**
     * A free position, decided entirely from a set the caller already holds.
     *
     * The pure twin of freePosition(): no query, no knowledge of the table,
     * just rejection sampling against what the caller says is taken. A batch
     * that has loaded the whole occupied set uses this and touches the
     * database not at all while it places systems.
     *
     * @param  array<string, true>  $taken  Keyed "x,y".
     * @param  Closure(int, int): int  $roll
     */
    public function freePositionAmong(array $taken, Closure $roll, int $attempts = 200): ?Position
    {
        for ($try = 0; $try < $attempts; $try++) {
            $position = new Position($roll(0, $this->size - 1), $roll(0, $this->size - 1), $this->id);

            if (! isset($taken["{$position->x},{$position->y}"])) {
                return $position;
            }
        }

        return null;
    }

    /** How many systems are standing on the grid right now. */
    public function occupied(): int
    {
        return System::query()->where('grid_id', $this->id)->count();
    }
}
