<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Characters\DownedCause;
use App\Characters\WaitingRoom;
use App\Models\Character;
use App\Stats\Damage;
use App\Stats\DamageOrigin;
use App\Stats\DamageResolver;
use App\Support\Points;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Hit points and Synaptic Integrity are carried to two decimals, and a whole figure is still an int. */
final class FractionalPointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_points_are_whole_when_whole_and_two_decimals_otherwise(): void
    {
        $this->assertSame(5, Points::of('5.00'));
        $this->assertSame(4.85, Points::of(4.8500001));
        $this->assertSame(9.9, Points::of(10.1 - 0.2));
        $this->assertSame('12', Points::format(12.0));
        $this->assertSame('7.25', Points::format(7.25));
    }

    public function test_damage_and_healing_keep_the_fraction(): void
    {
        $character = Character::factory()->create();
        $max = $character->maximumFor('current_hitpoints');

        $this->assertSame(2.5, $character->takeDamage('current_hitpoints', 2.5));
        $this->assertSame(Points::of($max - 2.5), $character->fresh()->current_hitpoints);

        $this->assertSame(1.25, $character->recover('current_hitpoints', 1.25));
        $this->assertSame(Points::of($max - 1.25), $character->fresh()->current_hitpoints);
    }

    public function test_a_hit_lands_to_the_hundredth(): void
    {
        // Pinned: the factory picks a random archetype, and some perks resist a type of damage, so the hit is then not a whole 5.
        $character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 10), ['archetype' => 3]));
        $max = $character->maximumFor('current_hitpoints');

        $result = DamageResolver::make()->apply($character, Damage::hitpoints(5, DamageOrigin::Penetrating));

        $this->assertSame(5, $result->taken);
        $this->assertSame(0.4, $character->takeDamage('current_hitpoints', 0.4));
        $this->assertSame(Points::of($max - 5.4), $character->fresh()->current_hitpoints);
    }

    public function test_somebody_on_a_fraction_of_a_point_is_not_on_the_floor(): void
    {
        $character = Character::factory()->create();
        $character->forceFill(['current_hitpoints' => 0.4])->save();

        $this->assertFalse(app(WaitingRoom::class)->checkFloor(
            $character->fresh(),
            new DownedCause(reason: 'test'),
        ));
    }
}
