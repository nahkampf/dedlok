<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Characters\Fatigue;
use App\Checks\CheckRoller;
use App\Combat\Fight\FightEngine;
use App\Combat\Fights;
use App\Models\Character;
use App\Models\Npc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\QueueDice;
use Tests\TestCase;

/**
 * The minute clock that takes fatigue down. `characters:regenerate` is where it runs.
 */
final class FatigueClockTest extends TestCase
{
    use RefreshDatabase;

    private function tired(float $by = 100): Character
    {
        $character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        app(Fatigue::class)->add($character, $by);

        return $character->fresh();
    }

    public function test_a_minute_takes_half_a_point_off(): void
    {
        $character = $this->tired();

        $this->artisan('characters:regenerate')->assertSuccessful();

        $this->assertSame(99.5, $character->fresh()->current_fatigue);
    }

    public function test_it_never_goes_below_nothing(): void
    {
        $character = $this->tired(0.3);

        $this->artisan('characters:regenerate')->assertSuccessful();

        $this->assertSame(0, $character->fresh()->current_fatigue);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $character = $this->tired();

        $this->artisan('characters:regenerate', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(100, $character->fresh()->current_fatigue);
    }

    public function test_the_clock_stops_in_a_fight(): void
    {
        $character = $this->tired();
        $npc = Npc::query()->create(['slug' => 'dummy', 'name' => 'Dummy', 'grade' => 1, 'hitpoints' => 10, 'aim' => 5, 'defence' => 5, 'pursuit' => 5]);
        (new Fights(new FightEngine(CheckRoller::fromConfig()), new QueueDice([])))->open($character, $npc);

        $this->artisan('characters:regenerate')->assertSuccessful();

        $this->assertSame(100, $character->fresh()->current_fatigue);
    }
}
