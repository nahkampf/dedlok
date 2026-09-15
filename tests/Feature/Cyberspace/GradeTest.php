<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\Grade;
use App\Cyberspace\GridSeeder;
use App\Cyberspace\Position;
use App\Cyberspace\Sweep;
use App\Cyberspace\SystemType;
use App\Models\Character;
use App\Models\System;
use App\Models\SystemContact;
use App\Models\SystemScan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/**
 * How hard a system is, and the fact that you are not told.
 *
 * The load-bearing half of this file is the second one. A grade is the number a
 * SCAN exists to discover — the difference between a system you can take and
 * one that ends your character — so a sweep must not reveal it and the map must
 * not draw it. Those tests are what stop it leaking the first time somebody
 * adds a helpful tooltip.
 */
final class GradeTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 16);
        Config::set('cyberspace.view_range', 2);
    }

    /** A loaded roll, so the distribution can be asserted rather than hoped at. */
    private function pinned(int $to): callable
    {
        return static fn (int $min, int $max): int => $to;
    }

    // ---- the number --------------------------------------------------------

    public function test_grade_runs_one_to_nine(): void
    {
        $this->assertSame(1, Grade::MIN);
        $this->assertSame(9, Grade::MAX);
        $this->assertSame(range(1, 9), Grade::all());

        $this->assertFalse(Grade::isValid(0));
        $this->assertTrue(Grade::isValid(1));
        $this->assertTrue(Grade::isValid(9));
        $this->assertFalse(Grade::isValid(10));
    }

    public function test_every_grade_has_a_name(): void
    {
        foreach (Grade::all() as $grade) {
            $this->assertNotSame("cyberspace.grades.{$grade}", Grade::label($grade));
        }
    }

    /** The weights are read from config and add up to something usable. */
    public function test_the_configured_weights_cover_every_grade(): void
    {
        $weights = Grade::weights();

        $this->assertSame(Grade::all(), array_keys($weights));
        $this->assertGreaterThan(0, array_sum($weights));

        // Steeply weighted towards the harmless: the easiest grade must be far
        // commoner than the hardest, or the world is an obstacle course.
        $this->assertGreaterThan($weights[9] * 10, $weights[1]);
    }

    public function test_the_roll_lands_inside_the_range(): void
    {
        // The first ticket always falls in the first bucket.
        $this->assertSame(1, Grade::roll($this->pinned(1)));

        // The last ticket falls in the last bucket that has any weight.
        $total = array_sum(Grade::weights());
        $this->assertSame(9, Grade::roll($this->pinned($total)));
    }

    public function test_a_broken_weight_table_falls_back_to_the_floor(): void
    {
        Config::set('cyberspace.grades', []);

        $this->assertSame(Grade::MIN, Grade::roll($this->pinned(1)));
    }

    public function test_weights_outside_the_range_are_ignored(): void
    {
        Config::set('cyberspace.grades', [0 => 500, 4 => 10, 99 => 500]);

        $this->assertSame([4 => 10], Grade::weights());
        $this->assertSame(4, Grade::roll($this->pinned(1)));
    }

    // ---- the world ---------------------------------------------------------

    public function test_a_generated_world_grades_every_machine(): void
    {
        Config::set('cyberspace.occupancy', 60);

        GridSeeder::fromConfig(fn (int $min, int $max): int => random_int($min, $max))->run();

        $grades = System::query()->where('type', '!=', SystemType::Portal->value)->pluck('grade');

        $this->assertGreaterThan(0, $grades->count());

        foreach ($grades as $grade) {
            $this->assertTrue(Grade::isValid($grade), "grade {$grade} is off the scale");
        }
    }

    /**
     * Mostly ordinary machines, with the occasional thing that should not be
     * touched. A world where a third of the grid is lethal is an obstacle
     * course rather than a place.
     */
    public function test_the_world_is_mostly_harmless(): void
    {
        Config::set('cyberspace.occupancy', 70);

        GridSeeder::fromConfig(fn (int $min, int $max): int => random_int($min, $max))->run();

        $graded = System::query()->where('type', '!=', SystemType::Portal->value);
        $total = $graded->count();
        $easy = (clone $graded)->where('grade', '<=', 3)->count();
        $lethal = (clone $graded)->where('grade', '>=', 8)->count();

        $this->assertGreaterThan($total * 0.5, $easy, 'most of the grid should be walkable');
        $this->assertLessThan($total * 0.1, $lethal, 'the worst should be rumours');
    }

    // ---- and you are not told ----------------------------------------------

    /**
     * A SWEEP DOES NOT REVEAL A GRADE. It tells you a machine is there, what it
     * calls itself, who runs it and what it answers to. What it would cost you
     * to break into is what a scan is for.
     */
    public function test_looking_at_a_system_does_not_reveal_its_grade(): void
    {
        $character = Character::factory()->create(['archetype' => 3]);

        System::factory()->at(8, 9)->grade(9)->create(['address' => 'DEADBEEF', 'name' => 'Cold Vault']);

        Sweep::fromConfig()->from($character, new Position(8, 8));

        $contact = SystemContact::query()->sole();

        $this->assertSame('Cold Vault', $contact->name);
        $this->assertFalse(
            Schema::hasColumn('system_contacts', 'grade'),
            'a sighting must not carry a grade — that is what a scan is for',
        );
        $this->assertArrayNotHasKey('grade', $contact->getAttributes());
    }

    /** And the map does not draw it either. */
    /**
     * A SWEEP TELLS YOU NOTHING ABOUT HOW HARD IT IS.
     *
     * The rule has one exception now and it is the one that makes scanning
     * worth doing: a grade somebody found out is theirs to see. What may never
     * appear is one nobody has read out of the machine — which is what this
     * test holds, by only ever sweeping.
     */
    public function test_the_map_does_not_show_an_unscanned_grade(): void
    {
        $user = User::factory()->create();
        $character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['user_id' => $user->id, 'archetype' => 3],
        ));

        System::factory()->at(8, 9)->grade(9)->create(['address' => 'DEADBEEF', 'name' => 'Cold Vault']);
        // The map is fetched as the player, so they need a deck that runs:
        // the readiness net ejects anybody inside without one.
        $this->equipReadyDeck($character);
        $character->forceFill(['grid_x' => 8, 'grid_y' => 8])->save();
        Sweep::fromConfig()->from($character->fresh(), new Position(8, 8));

        $response = $this->actingAs($user)->get(route('cyberspace.index'))->assertOk();

        $response->assertSee('Cold Vault');

        // None of the grade vocabulary may appear on a screen a sweep produced.
        foreach (Grade::all() as $grade) {
            $response->assertDontSee(Grade::label($grade));
        }

        // Nor the bar that would draw one. Matched on the grade 9 ratio
        // rather than on `--g:`, because the legend carries a sample swatch of
        // its own and it is supposed to.
        $response->assertDontSee('--g:1', false);
    }

    /**
     * And a grade that WAS scanned is drawn, as a bar at the foot of the cell.
     *
     * The map is where scanning pays off: the number a scan exists to discover
     * is a number you can then see from across the grid.
     */
    public function test_the_map_shows_a_grade_that_was_scanned(): void
    {
        $user = User::factory()->create();
        $character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['user_id' => $user->id, 'archetype' => 3],
        ));

        System::factory()->at(8, 9)->grade(9)->create(['address' => 'DEADBEEF', 'name' => 'Cold Vault']);
        $this->equipReadyDeck($character);
        $character->forceFill(['grid_x' => 8, 'grid_y' => 8])->save();
        Sweep::fromConfig()->from($character->fresh(), new Position(8, 8));

        SystemScan::query()->create([
            'character_id' => $character->id,
            'address' => 'DEADBEEF',
            'x' => 8,
            'y' => 9,
            'grade' => 9,
            'passes' => 1,
            'scanned_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        $this->actingAs($user)->get(route('cyberspace.index'))
            ->assertOk()
            ->assertSee('--g:1', false);
    }

    /** A file that has gone stale says nothing, which is what perishing means. */
    public function test_an_expired_scan_stops_showing_its_grade(): void
    {
        $user = User::factory()->create();
        $character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['user_id' => $user->id, 'archetype' => 3],
        ));

        System::factory()->at(8, 9)->grade(9)->create(['address' => 'DEADBEEF', 'name' => 'Cold Vault']);
        $this->equipReadyDeck($character);
        $character->forceFill(['grid_x' => 8, 'grid_y' => 8])->save();
        Sweep::fromConfig()->from($character->fresh(), new Position(8, 8));

        SystemScan::query()->create([
            'character_id' => $character->id,
            'address' => 'DEADBEEF',
            'x' => 8,
            'y' => 9,
            'grade' => 9,
            'passes' => 1,
            'scanned_at' => now()->subDays(3),
            'expires_at' => now()->subDay(),
        ]);

        $this->actingAs($user)->get(route('cyberspace.index'))
            ->assertOk()
            ->assertDontSee('--g:1', false);
    }
}
