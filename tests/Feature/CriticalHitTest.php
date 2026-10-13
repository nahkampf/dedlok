<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Checks\CheckRoller;
use App\Combat\Fight\Arm;
use App\Combat\Fight\Combatant;
use App\Combat\Fight\FightEngine;
use App\Combat\Fight\FightState;
use App\Combat\Fight\RoundSpec;
use App\Combat\Fight\Side;
use App\Combat\WeaponClass;
use App\Cyberspace\CriticalHit;
use App\Models\Character;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MapMitigation;
use Tests\Support\QueueDice;
use Tests\TestCase;

/** Critical hits, in both realms: 2% by default, five times the damage, rolled only for a hit that lands. */
final class CriticalHitTest extends TestCase
{
    use RefreshDatabase;

    private function knife(): Arm
    {
        return new Arm('Knife', WeaponClass::Blade, 10.0, 1, ['s' => new RoundSpec('s', 'S', 4.0, ['slashing' => 100])], 's');
    }

    private function fight(float $crit, QueueDice $dice): FightState
    {
        $state = new FightState(
            new Combatant('You', 100, 10.0, melee: $this->knife(), si: 30, critChance: $crit),
            new Combatant('Foe', 100, 10.0, melee: $this->knife()),
            distance: 0,
            first: Side::A,
        );

        return (new FightEngine(CheckRoller::fromConfig()))->attack($state, Side::A, $dice, new MapMitigation);
    }

    public function test_a_critical_hit_does_five_times_the_damage(): void
    {
        // Dead-on, a place, then the critical die (0.0 is under 100%).
        $after = $this->fight(100.0, new QueueDice([0.0, 0.5, 0.0]));

        $this->assertSame(100 - 20, $after->combatant(Side::B)->hp);
    }

    public function test_no_chance_means_no_die_and_ordinary_damage(): void
    {
        $after = $this->fight(0.0, new QueueDice([0.0, 0.5]));

        $this->assertSame(100 - 4, $after->combatant(Side::B)->hp);
    }

    public function test_a_failed_critical_roll_is_an_ordinary_hit(): void
    {
        $after = $this->fight(2.0, new QueueDice([0.0, 0.5, 0.5]));

        $this->assertSame(100 - 4, $after->combatant(Side::B)->hp);
    }

    public function test_both_chances_start_at_two_per_cent(): void
    {
        config(['stats.bases.critical_chance_physical' => 2, 'stats.bases.critical_chance_cyberspace' => 2]);
        $character = Character::factory()->create();

        $this->assertSame(2.0, $character->stat('critical_chance_physical')->value);
        $this->assertSame(2.0, $character->stat('critical_chance_cyberspace')->value);
    }

    public function test_the_cyberspace_die_is_a_tenth_of_a_per_cent_wide(): void
    {
        config(['stats.bases.critical_chance_cyberspace' => 2]);
        $character = Character::factory()->create();

        $this->assertTrue(CriticalHit::rolls($character, static fn (int $min, int $max): int => 20));
        $this->assertFalse(CriticalHit::rolls($character, static fn (int $min, int $max): int => 21));
        $this->assertSame(50, CriticalHit::scale(10));
    }
}
