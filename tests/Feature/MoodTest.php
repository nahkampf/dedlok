<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Characters\Mood;
use App\Models\Character;
use App\Models\CharacterModifier;
use App\Stats\InjuryLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mood: a stored figure, moved by events, drifting to 0, with bands that grant effects. TODO.md, *Mood*.
 */
final class MoodTest extends TestCase
{
    use RefreshDatabase;

    private function character(): Character
    {
        return Character::factory()->create(array_fill_keys(Character::ATTRIBUTES, 20) + ['archetype' => 3]);
    }

    public function test_it_clamps_and_grants_the_band_effect(): void
    {
        $character = $this->character();
        $mood = new Mood;

        $mood->add($character, 500);
        $this->assertSame(100, $character->refresh()->mood);
        $this->assertTrue(CharacterModifier::query()->where('character_id', $character->id)->where('definition_key', 'mood_elated')->exists());

        $mood->add($character, -150);
        $this->assertSame(-50, $character->refresh()->mood);
        $this->assertSame(['mood_low'], CharacterModifier::query()->where('character_id', $character->id)->pluck('definition_key')->all());
        $this->assertSame('Low', $mood->reading($character)['word']);
    }

    public function test_it_drifts_to_zero_and_stops_there(): void
    {
        $character = $this->character();
        $mood = new Mood;
        $mood->add($character, 45);

        config(['mood.drift_per_minute' => 10]);
        $mood->drift($character);
        $this->assertSame(35, $character->refresh()->mood);
        $this->assertSame(0, CharacterModifier::query()->where('character_id', $character->id)->count());

        config(['mood.drift_per_minute' => 100]);
        $mood->drift($character);
        $this->assertSame(0, $character->refresh()->mood);
    }

    public function test_it_drifts_faster_past_seventy(): void
    {
        $character = $this->character();
        $mood = new Mood;
        $mood->add($character, 80);

        $mood->drift($character);
        $this->assertEquals(79.97, $character->refresh()->mood);

        $mood->add($character, -20);
        $mood->drift($character);
        $this->assertEquals(59.96, $character->refresh()->mood);
    }

    public function test_an_injury_lowers_it_by_the_points_of_harm(): void
    {
        $character = $this->character();

        app(InjuryLedger::class)->inflict($character, 'neural_damage', 3);

        $this->assertEquals(-3, $character->refresh()->mood);
    }
}
