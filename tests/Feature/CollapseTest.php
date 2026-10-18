<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Characters\Collapse;
use App\Characters\Fatigue;
use App\Characters\WaitingRoom;
use App\Checks\CheckRoller;
use App\City\ActivityKind;
use App\Combat\Fight\FightEngine;
use App\Combat\Fights;
use App\Models\Character;
use App\Models\CharacterModifier;
use App\Models\CityActivity;
use App\Models\District;
use App\Models\Npc;
use App\Support\Points;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\QueueDice;
use Tests\TestCase;

/**
 * Running out of yourself. FATIGUE.md, *Collapse*.
 */
final class CollapseTest extends TestCase
{
    use RefreshDatabase;

    /** @param  int  $at  what the mugging die says: low mugs (at its minimum share), high does not */
    private function collapse(int $at = 100): Collapse
    {
        return new Collapse(static fn (int $min, int $max): int => $at === 100 ? $max : $min);
    }

    private function spent(int $creds = 100): Character
    {
        $character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3, 'creds' => $creds]));
        app(Fatigue::class)->add($character, 9999);

        return $character->fresh();
    }

    private function district(Character $character): string
    {
        return District::query()->findOrFail($character->fresh()->district_id)->slug;
    }

    public function test_reaching_the_maximum_only_flags_a_collapse(): void
    {
        $character = $this->spent();

        $this->assertSame(260, $character->current_fatigue);
        $this->assertTrue($character->collapse_pending);
        $this->assertNull($character->waiting_until, 'nothing happens inside the writer');
    }

    public function test_nothing_pending_nothing_happens(): void
    {
        $character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));

        $this->assertFalse($this->collapse()->trigger($character));
        $this->assertNull($character->fresh()->waiting_until);
    }

    public function test_a_collapse_hurts_the_mind_when_unsure_moves_you_home_and_puts_you_down_for_two_minutes(): void
    {
        $character = $this->spent();
        $hp = $character->current_hitpoints;
        $si = $character->current_synaptic_integrity;
        // 20 percent of the maximum AS IT STANDS: at the ceiling they are Drained, which has already lowered it.
        $cost = Points::of($character->maximumFor('current_synaptic_integrity') * 0.2);

        $this->assertTrue($this->collapse()->trigger($character));

        $fresh = $character->fresh();
        $this->assertSame($cost, $fresh->waiting_cause['damage']);
        $this->assertLessThanOrEqual(Points::of($si - $cost), $fresh->current_synaptic_integrity, 'and Exhausted then lowers the ceiling, which clamps what is left');
        $this->assertSame(min($hp, $fresh->maximumFor('current_hitpoints')), $fresh->current_hitpoints, 'the body takes no damage, only the lower ceiling Exhausted leaves');
        $this->assertSame('knightsbridge-projects', $this->district($fresh));
        $this->assertFalse($fresh->collapse_pending);
        $this->assertSame('collapse', $fresh->waiting_cause['reason']);
        $this->assertEqualsWithDelta(120, now()->diffInSeconds($fresh->waiting_until, false), 2);
        $this->assertTrue(CharacterModifier::query()->where('character_id', $fresh->id)->where('definition_key', 'fatigue_exhausted')->exists());
    }

    public function test_it_is_a_mugging_where_you_fell_when_the_die_says_so_and_not_otherwise(): void
    {
        $robbed = $this->spent(100);
        $this->collapse(1)->trigger($robbed);
        $this->assertSame(90, $robbed->fresh()->creds, 'the least share, 10 percent');

        $spared = $this->spent(100);
        $this->collapse(100)->trigger($spared);
        $this->assertSame(100, $spared->fresh()->creds);
    }

    public function test_a_fight_ends_and_the_body_is_what_pays(): void
    {
        $character = $this->spent();
        $npc = Npc::query()->create(['slug' => 'dummy', 'name' => 'Dummy', 'grade' => 1, 'hitpoints' => 10, 'aim' => 5, 'defence' => 5, 'pursuit' => 5]);
        (new Fights(new FightEngine(CheckRoller::fromConfig()), new QueueDice([])))->open($character->fresh(), $npc);
        $this->assertNotNull($character->fresh()->current_fight_id);
        $hp = $character->fresh()->current_hitpoints;
        $si = $character->fresh()->current_synaptic_integrity;
        $cost = Points::of($character->fresh()->maximumFor('current_hitpoints') * 0.2);

        $this->collapse()->trigger($character->fresh());

        $fresh = $character->fresh();
        $this->assertNull($fresh->current_fight_id);
        $this->assertSame($cost, $fresh->waiting_cause['damage']);
        $this->assertLessThanOrEqual(Points::of($hp - $cost), $fresh->current_hitpoints);
        $this->assertSame(min($si, $fresh->maximumFor('current_synaptic_integrity')), $fresh->current_synaptic_integrity);
    }

    public function test_a_city_trip_ends_where_it_stood_and_costs_the_body(): void
    {
        $character = $this->spent();
        CityActivity::query()->create([
            'character_id' => $character->id, 'kind' => ActivityKind::Explore, 'step' => 0, 'paid' => 0, 'cancelling' => false, 'pass_ends_at' => now()->addMinutes(5),
        ]);

        $hp = $character->fresh()->current_hitpoints;
        $cost = Points::of($character->fresh()->maximumFor('current_hitpoints') * 0.2);

        $this->collapse()->trigger($character->fresh());

        $this->assertNull(CityActivity::query()->find($character->id));
        $this->assertSame($cost, $character->fresh()->waiting_cause['damage']);
        $this->assertLessThanOrEqual(Points::of($hp - $cost), $character->fresh()->current_hitpoints);
    }

    public function test_being_in_cyberspace_ends_it_and_costs_the_mind(): void
    {
        $character = $this->spent();
        $character->forceFill(['grid_x' => 4, 'grid_y' => 4])->save();
        $si = $character->fresh()->current_synaptic_integrity;
        $cost = Points::of($character->fresh()->maximumFor('current_synaptic_integrity') * 0.2);

        $this->collapse()->trigger($character->fresh());

        $fresh = $character->fresh();
        $this->assertNull($fresh->grid_x);
        $this->assertSame($cost, $fresh->waiting_cause['damage']);
        $this->assertLessThanOrEqual(Points::of($si - $cost), $fresh->current_synaptic_integrity, 'and Exhausted then lowers the ceiling, which clamps what is left');
    }

    public function test_you_wake_at_ninety_five_percent_and_still_exhausted(): void
    {
        $character = $this->spent();
        $this->collapse()->trigger($character);

        $this->travel(3)->minutes();
        $this->assertTrue(app(WaitingRoom::class)->release($character->fresh()));

        $fresh = $character->fresh();
        $this->assertSame(247, $fresh->current_fatigue);
        $this->assertNull($fresh->waiting_until);
        $this->assertTrue(CharacterModifier::query()->where('character_id', $fresh->id)->where('definition_key', 'fatigue_exhausted')->exists());
        $this->assertFalse($fresh->collapse_pending);
    }

    public function test_the_next_request_collapses_them_and_the_screen_says_so(): void
    {
        $character = $this->spent();

        $this->actingAs($character->user)->get(route('character.index'))->assertRedirect(route('waiting.show'));

        $this->actingAs($character->user)->get(route('waiting.show'))
            ->assertOk()
            ->assertSee('Collapse')
            ->assertSee('You have collapsed from the combined effects of stress and exertion.');
    }
}
