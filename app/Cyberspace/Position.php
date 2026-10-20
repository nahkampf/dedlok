<?php

declare(strict_types=1);

namespace App\Cyberspace;

use Stringable;

/**
 * A place on the grid. Two integers and nothing else.
 *
 * A position NEVER MOVES AND NEVER CHANGES — that is the whole of what it is,
 * and it is why there is no positions table and no id here. The grid is
 * arithmetic. What moves is a system, which HAS a position the way an item has
 * a tier, and which will have a different one tomorrow.
 *
 * Pure, so the seeder, the map and the mover can all reason about places
 * without a database between them: nothing here knows what is standing at one.
 * Grid answers that.
 *
 * Bounds are deliberately NOT checked here. A position off the edge is a
 * perfectly good pair of numbers and a perfectly bad place to walk to; which of
 * those it is depends on the size of the grid, and the size of the grid is
 * Grid's business. Position that validated itself would need to know it, and
 * then every one of these would carry a copy of the world.
 */
final readonly class Position implements Stringable
{
    public function __construct(
        public int $x,
        public int $y,
        /** Which grid (`grids.id`). 1 is the main grid, so every existing `new Position($x, $y)` still means it. */
        public int $grid = 1,
    ) {}

    public static function of(int $x, int $y): self
    {
        return new self($x, $y);
    }

    public function equals(self $other): bool
    {
        return $this->grid === $other->grid && $this->x === $other->x && $this->y === $other->y;
    }

    /**
     * Distance in STEPS, which is the Chebyshev distance and not the Euclidean
     * one: a diagonal step is one step, so the eight cells around a position
     * are all one away.
     *
     * Movement is eight-directional and the viewing window is a square, and
     * those two facts are the same fact. A round window on a square grid is a
     * lie told in half-lit cells, and a four-directional walk under a square
     * window would show the player corners they cannot reach.
     */
    public function distanceTo(self $other): int
    {
        return max(abs($this->x - $other->x), abs($this->y - $other->y));
    }

    public function isAdjacentTo(self $other): bool
    {
        return $this->grid === $other->grid && ! $this->equals($other) && $this->distanceTo($other) === 1;
    }

    /**
     * The eight positions around this one, in reading order.
     *
     * Unclipped: some of them may be off the edge, because whether they are is
     * a question about the grid rather than about this position. Grid::contains()
     * is the filter, and Grid::step() applies it.
     *
     * @return list<self>
     */
    public function neighbours(): array
    {
        $around = [];

        for ($dy = -1; $dy <= 1; $dy++) {
            for ($dx = -1; $dx <= 1; $dx++) {
                if ($dx !== 0 || $dy !== 0) {
                    $around[] = new self($this->x + $dx, $this->y + $dy, $this->grid);
                }
            }
        }

        return $around;
    }

    /** How a position is written wherever a player sees one. */
    public function __toString(): string
    {
        return "{$this->x}, {$this->y}";
    }
}
