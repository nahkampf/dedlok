<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Checks\CheckRoller;
use App\Cyberspace\HunterCombat;
use App\Cyberspace\Wardens;
use App\Models\Character;
use App\Models\Hunt;
use App\Models\Warden;
use App\Stats\Resolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/**
 * Wardens (CYBERSPACE.md, *Wardens*): hunters that walk a subnet.
 */
final class WardenTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 16);
        DB::table('grids')->insert([
            'id' => 2, 'slug' => 'mil', 'name' => 'Military', 'prefix' => 'mil', 'size' => 12, 'entry_x' => 6, 'entry_y' => 6,
            'spec' => json_encode(['wardens' => ['count' => 3, 'grade_min' => 2, 'grade_max' => 4]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        $this->equipReadyDeck($this->character);
        $this->character->forceFill(['grid_id' => 2, 'grid_x' => 3, 'grid_y' => 3])->save();
    }

    private function wardens(int $die = 0): Wardens
    {
        return new Wardens(app(Resolver::class), app(CheckRoller::class), static fn (int $min, int $max): int => min($max, max($min, $die)));
    }

    private function dice(float $roll): void
    {
        // 1.0 fails the runner's check (the warden notices); 0.0 passes it.
        $this->instance(CheckRoller::class, CheckRoller::fromConfig(static fn (): float => $roll));
    }

    private function warden(int $x, int $y, int $grade = 1): Warden
    {
        return Warden::query()->create(['grid_id' => 2, 'x' => $x, 'y' => $y, 'grade' => $grade, 'moved_at' => now()]);
    }

    public function test_a_subnet_is_stood_up_to_its_spec_and_never_near_the_entry(): void
    {
        $roll = static fn (int $min, int $max): int => random_int($min, $max);
        $made = (new Wardens(app(Resolver::class), app(CheckRoller::class), $roll))->populate(2);

        $this->assertSame(3, $made);
        $this->assertSame(3, Warden::query()->count());
        Warden::query()->get()->each(function (Warden $w): void {
            $this->assertGreaterThanOrEqual(2, $w->grade);
            $this->assertLessThanOrEqual(4, $w->grade);
            $this->assertGreaterThanOrEqual(4, max(abs($w->x - 6), abs($w->y - 6)));
        });

        $this->assertSame(0, (new Wardens(app(Resolver::class), app(CheckRoller::class), $roll))->populate(2), 'already at its count');
    }

    public function test_one_step_a_minute_inside_the_grid_and_none_before_it_is_due(): void
    {
        $w = $this->warden(0, 0);
        $this->assertSame([], $this->wardens()->settle(2), 'not due yet');

        $w->forceFill(['moved_at' => now()->subSeconds(61)])->save();
        $this->wardens()->settle(2);

        $w->refresh();
        $this->assertSame(1, max(abs($w->x), abs($w->y)), 'one step from the corner, and still on the grid');
        $this->assertGreaterThanOrEqual(0, min($w->x, $w->y));
    }

    public function test_a_long_absence_is_caught_up_by_at_most_a_few_steps(): void
    {
        $w = $this->warden(0, 0);
        $w->forceFill(['moved_at' => now()->subHours(5)])->save();
        $this->wardens(9)->settle(2);

        $w->refresh();
        $this->assertLessThanOrEqual((int) config('cyberspace.wardens.catch_up'), max($w->x, $w->y));
    }

    public function test_a_warden_within_reach_that_notices_attacks_like_a_hunter(): void
    {
        $this->warden(4, 4, 3);
        $this->dice(1.0);

        $hunt = $this->wardens()->notice($this->character->fresh());

        $this->assertInstanceOf(Hunt::class, $hunt);
        $this->assertSame(3, $hunt->grade);
        $this->assertNotNull($hunt->warden_id);
        $this->assertSame($hunt->id, $this->character->fresh()->current_hunt_id);
    }

    public function test_a_runner_who_passes_the_check_is_left_alone(): void
    {
        $this->warden(4, 4, 3);
        $this->dice(0.0);

        $this->assertNull($this->wardens()->notice($this->character->fresh()));
    }

    public function test_reach_is_one_step_for_a_low_grade_and_two_for_a_high_one(): void
    {
        $this->dice(1.0);
        $near = $this->warden(5, 5, 1);

        $this->assertNull($this->wardens()->notice($this->character->fresh()), 'two steps away is out of a grade 1 warden\'s reach');

        $near->update(['grade' => 3]);
        $this->assertNotNull($this->wardens()->notice($this->character->fresh()), 'but inside a grade 3 warden\'s');
    }

    public function test_nothing_happens_to_the_busy_or_on_the_main_grid(): void
    {
        $this->warden(4, 4, 3);
        $this->dice(1.0);
        $this->character->forceFill(['waiting_until' => now()->addMinute()])->save();
        $this->assertNull($this->wardens()->notice($this->character->fresh()));

        $this->character->forceFill(['waiting_until' => null, 'grid_id' => 1])->save();
        $this->assertNull($this->wardens()->notice($this->character->fresh()));
    }

    public function test_the_minute_clock_moves_them_and_looks_at_whoever_they_come_near(): void
    {
        $this->dice(1.0);
        $w = $this->warden(3, 8, 3);
        $w->forceFill(['moved_at' => now()->subSeconds(61)])->save();
        $min = new Wardens(app(Resolver::class), app(CheckRoller::class), static fn (int $min, int $max): int => $min);

        // The smallest die steps up and to the left: (2,7) is four from the runner at (3,3), out of any reach.
        $min->minute();
        $this->assertSame([2, 7], [$w->fresh()->x, $w->fresh()->y]);
        $this->assertNull($this->character->fresh()->current_hunt_id);

        // From (3,6) the same step lands on (2,5), two away: a grade 3 warden is within reach and, the check failed, attacks.
        Warden::query()->whereKey($w->id)->update(['moved_at' => now()->subSeconds(61), 'x' => 3, 'y' => 6]);
        $min->minute();

        $this->assertNotNull($this->character->fresh()->current_hunt_id);
        $this->assertSame($w->id, Hunt::query()->latest('id')->first()->warden_id);
    }

    public function test_beating_the_hunter_removes_the_warden(): void
    {
        $this->character->forceFill(['heat' => 50])->save();
        $w = $this->warden(4, 4, 2);
        $this->dice(1.0);
        $hunt = $this->wardens()->notice($this->character->fresh());

        $combat = HunterCombat::fromConfig(app(Resolver::class), static fn (int $min, int $max): int => $max);
        $win = new \ReflectionMethod($combat, 'win');
        $win->setAccessible(true);
        $win->invoke($combat, $this->character->fresh(), $hunt->fresh());

        $this->assertDatabaseMissing('wardens', ['id' => $w->id]);
    }

    public function test_the_map_draws_a_warden_only_inside_the_runners_sight(): void
    {
        $this->warden(4, 3, 1);
        $page = fn (): string => $this->actingAs($this->character->user)->get(route('cyberspace.index'))->assertOk()->getContent();

        $this->assertStringContainsString('class="cell__warden"', $page());

        Warden::query()->update(['x' => 11, 'y' => 11]);
        $this->assertStringNotContainsString('class="cell__warden"', $page());
    }

    public function test_the_map_draws_another_jacked_in_runner_but_not_yourself(): void
    {
        $page = fn (): string => $this->actingAs($this->character->user)->get(route('cyberspace.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('class="cell__runner"', $page());

        $other = Character::factory()->create();
        Character::query()->whereKey($other->id)->update(['grid_id' => 2, 'grid_x' => 4, 'grid_y' => 3]);
        $this->assertStringContainsString('class="cell__runner"', $page());

        Character::query()->whereKey($other->id)->update(['grid_x' => null, 'grid_y' => null]);
        $this->assertStringNotContainsString('class="cell__runner"', $page());
    }
}
