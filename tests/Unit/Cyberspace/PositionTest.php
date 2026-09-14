<?php

declare(strict_types=1);

namespace Tests\Unit\Cyberspace;

use App\Cyberspace\Position;
use PHPUnit\Framework\TestCase;

/**
 * A place on the grid: two integers, no database, no bounds.
 *
 * Bounds are Grid's business — how big the world is is not a fact a coordinate
 * carries — so everything here is arithmetic and stays true at 64 and at 2048.
 */
final class PositionTest extends TestCase
{
    public function test_a_diagonal_step_is_one_step(): void
    {
        $here = new Position(10, 10);

        // Chebyshev, not Euclidean: movement is eight-directional and the
        // viewing window is a square, and those are the same fact.
        $this->assertSame(1, $here->distanceTo(new Position(11, 11)));
        $this->assertSame(1, $here->distanceTo(new Position(9, 11)));
        $this->assertSame(3, $here->distanceTo(new Position(13, 12)));
        $this->assertSame(0, $here->distanceTo(new Position(10, 10)));
    }

    public function test_the_eight_around_a_position_are_all_adjacent(): void
    {
        $here = new Position(4, 4);

        $this->assertCount(8, $here->neighbours());

        foreach ($here->neighbours() as $neighbour) {
            $this->assertTrue($here->isAdjacentTo($neighbour));
        }
    }

    public function test_a_position_is_not_adjacent_to_itself(): void
    {
        $here = new Position(4, 4);

        $this->assertFalse($here->isAdjacentTo(new Position(4, 4)));
        $this->assertFalse($here->isAdjacentTo(new Position(4, 6)));
    }

    /** Unclipped on purpose: whether a neighbour exists is a question about the grid. */
    public function test_the_neighbours_of_a_corner_include_places_off_the_edge(): void
    {
        $corner = new Position(0, 0);

        $offGrid = array_filter(
            $corner->neighbours(),
            static fn (Position $p): bool => $p->x < 0 || $p->y < 0
        );

        $this->assertCount(5, $offGrid);
    }

    public function test_equality_is_by_value(): void
    {
        $this->assertTrue((new Position(2, 3))->equals(new Position(2, 3)));
        $this->assertFalse((new Position(2, 3))->equals(new Position(3, 2)));
    }

    public function test_it_reads_as_a_coordinate(): void
    {
        $this->assertSame('12, 43', (string) new Position(12, 43));
    }
}
