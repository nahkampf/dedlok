<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\Grid;
use App\Cyberspace\Position;
use App\Cyberspace\SystemType;
use App\Models\System;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * The one class that knows how the world is stored.
 *
 * Everything here is asked of Grid rather than of the table, deliberately: the
 * day 4.2 million rows become a generator with overrides, these tests are what
 * says the swap changed nothing a caller can see.
 */
final class GridTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A small world. The rules do not change with the size, and a 64 x 64
        // grid in a test is 4 096 rows to prove something an 8 x 8 proves.
        Config::set('cyberspace.size', 8);
        Config::set('cyberspace.view_range', 2);
    }

    private function grid(): Grid
    {
        return Grid::fromConfig();
    }

    public function test_the_portal_sits_near_the_middle(): void
    {
        $this->assertTrue($this->grid()->portal()->equals(new Position(4, 4)));
        $this->assertSame(64, $this->grid()->positions());
    }

    /**
     * THE GRID DOES NOT WRAP. A step off the edge is not a step to the far
     * side; it is not a step.
     */
    public function test_the_edges_are_edges(): void
    {
        $grid = $this->grid();

        $this->assertTrue($grid->contains(new Position(0, 0)));
        $this->assertTrue($grid->contains(new Position(7, 7)));
        $this->assertFalse($grid->contains(new Position(-1, 4)));
        $this->assertFalse($grid->contains(new Position(8, 4)));
        $this->assertFalse($grid->contains(new Position(4, 8)));
    }

    public function test_it_says_what_is_standing_at_a_position(): void
    {
        $system = System::factory()->at(3, 5)->create();

        $found = $this->grid()->at(new Position(3, 5));

        $this->assertNotNull($found);
        $this->assertTrue($found->is($system));
    }

    /** Empty is 30% of the grid by design, and never an error. */
    public function test_an_empty_position_is_an_ordinary_answer(): void
    {
        $this->assertNull($this->grid()->at(new Position(3, 5)));
        $this->assertTrue($this->grid()->isEmpty(new Position(3, 5)));
    }

    public function test_an_address_resolves_to_the_system_wearing_it(): void
    {
        $system = System::factory()->at(1, 1)->create(['address' => 'A3F91C08']);

        $found = $this->grid()->find('  a3f91c08 ');

        $this->assertNotNull($found, 'a typed address is normalised before it is looked up');
        $this->assertTrue($found->is($system));
    }

    /**
     * Addresses die with the systems that wore them, so a jump to one traded
     * last week is allowed to arrive nowhere. That is the world moving on.
     */
    public function test_an_address_nothing_answers_to_is_null_rather_than_an_error(): void
    {
        $this->assertNull($this->grid()->find('DEADBEEF'));
        $this->assertNull($this->grid()->find('not an address'));
    }

    public function test_the_viewing_window_is_a_square_that_clips_at_the_edge(): void
    {
        foreach ([[0, 0], [1, 1], [2, 2], [3, 3], [5, 5]] as [$x, $y]) {
            System::factory()->at($x, $y)->create();
        }

        $grid = $this->grid();

        // Range 2 around the corner: the window is clipped, not wrapped, so
        // (5, 5) is outside it and nothing from the far edge is inside.
        $seen = $grid->within(new Position(0, 0))->map(
            fn (System $s): string => (string) $s->position()
        )->all();

        $this->assertEqualsCanonicalizing(['0, 0', '1, 1', '2, 2'], $seen);
    }

    public function test_a_step_is_one_position_and_inside_the_grid(): void
    {
        $grid = $this->grid();

        $this->assertTrue($grid->isStep(new Position(4, 4), new Position(5, 5)));
        $this->assertFalse($grid->isStep(new Position(4, 4), new Position(6, 4)), 'two positions is two steps');
        $this->assertFalse($grid->isStep(new Position(4, 4), new Position(4, 4)), 'standing still is not a step');
        $this->assertFalse($grid->isStep(new Position(0, 0), new Position(-1, 0)), 'the edge is an edge');
    }

    /**
     * A system does not block a position. Walking onto one is how you look at
     * it — there is nothing else to do with a place.
     */
    public function test_a_system_does_not_block_the_position_it_stands_on(): void
    {
        System::factory()->at(5, 5)->create();

        $this->assertTrue($this->grid()->isStep(new Position(4, 4), new Position(5, 5)));
    }

    public function test_a_free_position_is_one_nothing_is_standing_on(): void
    {
        // Fill everything but (7, 7).
        for ($x = 0; $x < 8; $x++) {
            for ($y = 0; $y < 8; $y++) {
                if ($x !== 7 || $y !== 7) {
                    System::factory()->at($x, $y)->create();
                }
            }
        }

        $free = $this->grid()->freePosition(fn (int $min, int $max): int => random_int($min, $max), attempts: 5000);

        $this->assertNotNull($free);
        $this->assertTrue($free->equals(new Position(7, 7)));
    }

    /**
     * A caller building a batch has positions that are spoken for and not yet
     * written, and the table cannot know about those.
     */
    public function test_a_position_spoken_for_by_this_pass_is_not_offered_again(): void
    {
        for ($x = 0; $x < 8; $x++) {
            for ($y = 0; $y < 8; $y++) {
                if (($x !== 7 || $y !== 7) && ($x !== 6 || $y !== 6)) {
                    System::factory()->at($x, $y)->create();
                }
            }
        }

        $free = $this->grid()->freePosition(
            fn (int $min, int $max): int => random_int($min, $max),
            taken: ['7,7' => true],
            attempts: 5000,
        );

        $this->assertNotNull($free);
        $this->assertTrue($free->equals(new Position(6, 6)));
    }

    public function test_a_full_grid_has_nowhere_to_put_anything(): void
    {
        for ($x = 0; $x < 8; $x++) {
            for ($y = 0; $y < 8; $y++) {
                System::factory()->at($x, $y)->create();
            }
        }

        $this->assertNull($this->grid()->freePosition(
            fn (int $min, int $max): int => random_int($min, $max)
        ));
    }

    public function test_the_portal_is_the_one_system_nothing_may_be_done_to(): void
    {
        $this->assertFalse(SystemType::Portal->isInteractable());

        foreach (SystemType::ordinary() as $type) {
            $this->assertTrue($type->isInteractable());
            $this->assertNotSame(SystemType::Portal, $type);
        }
    }
}
