<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Characters\Fatigue;
use App\City\ActivityKind;
use App\Models\Character;
use App\Models\CityActivity;
use App\Models\Notification;
use App\Models\Safehouse;
use App\Safehouses\Action;
use App\Safehouses\HomeActions;
use App\Safehouses\Refusal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Work out, Study and Simstim: meters, gains, mood and the daily allowance. */
final class SafehouseSessionTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    private HomeActions $home;

    protected function setUp(): void
    {
        parent::setUp();

        $box = Safehouse::fallback();
        $box->forceFill(['actions' => ['workout', 'study', 'simstim']])->save();
        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3, 'district_id' => $box->district_id, 'current_fatigue' => 0, 'mood' => 0]));
        $this->home = new HomeActions;
    }

    public function test_working_out_fills_the_meter_and_tires(): void
    {
        $this->assertTrue($this->home->startSession($this->character, Action::Workout));

        $this->home->settleSession($this->character, now()->addMinutes(10));

        $fresh = $this->character->fresh();
        $this->assertEqualsWithDelta(3.0, (float) $fresh->workout_progress, 0.01);
        $this->assertGreaterThan(0, (float) $fresh->current_fatigue);
    }

    public function test_a_full_meter_gains_a_point_sends_a_message_and_resets(): void
    {
        $this->character->forceFill(['study_progress' => 99.9])->save();
        $this->home->startSession($this->character, Action::Study);

        $this->home->settleSession($this->character, now()->addMinute());

        $fresh = $this->character->fresh();
        $this->assertSame(0.0, (float) $fresh->study_progress);
        $this->assertSame(61, $fresh->intellect + $fresh->memory + $fresh->focus);
        $this->assertSame('Sharpening the mind', Notification::query()->latest('id')->firstOrFail()->title);
    }

    public function test_a_workout_never_crosses_the_collapse_line_and_hands_over_to_a_rest(): void
    {
        Safehouse::fallback()->forceFill(['actions' => ['workout', 'study', 'simstim', 'rest'], 'rest_rate' => 1])->save();
        $max = (float) (new Fatigue)->maximum($this->character);
        $this->character->forceFill(['current_fatigue' => $max - 5])->save();
        $this->assertTrue($this->home->startSession($this->character->fresh(), Action::Workout));

        // Far longer than there is room for: it would have been a collapse.
        $this->home->settleSession($this->character, now()->addHours(2));

        $fresh = $this->character->fresh();
        $this->assertFalse((bool) $fresh->collapse_pending);
        $this->assertLessThan($max, (float) $fresh->current_fatigue);
        $this->assertSame(ActivityKind::Rest, CityActivity::query()->whereKey($fresh->id)->firstOrFail()->kind);
        $this->assertSame('Too tired to go on: resting', Notification::query()->latest('id')->firstOrFail()->title);
    }

    public function test_simstim_lifts_mood_and_stops_at_two_hours_a_day(): void
    {
        $this->assertTrue($this->home->startSession($this->character, Action::Simstim));

        $this->home->settleSession($this->character, now()->addHours(3));

        $fresh = $this->character->fresh();
        $this->assertSame(7200, $fresh->simstim_seconds);
        $this->assertEqualsWithDelta(48.0, (float) $fresh->mood, 0.1);
        $this->assertSame(Refusal::NothingToDo, $this->home->startSession($fresh, Action::Simstim));
    }
}
