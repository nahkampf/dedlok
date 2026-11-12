<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Achievements\Achievements;
use App\Achievements\Metric;
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

    public function test_the_better_you_already_are_the_slower_the_meter_fills(): void
    {
        // Sum 150 instead of 60: 0.3 x 60/150 = 0.12 a minute.
        $this->character->forceFill(['meat' => 50, 'motor' => 50, 'muscle' => 50])->save();
        $this->home->startSession($this->character->fresh(), Action::Workout);

        $this->home->settleSession($this->character->fresh(), now()->addMinutes(10));

        $this->assertEqualsWithDelta(1.2, (float) $this->character->fresh()->workout_progress, 0.01);
    }

    public function test_training_drains_mood_and_a_gain_lifts_it(): void
    {
        $this->home->startSession($this->character, Action::Workout);
        $this->home->settleSession($this->character, now()->addMinutes(10), stop: true);

        // -0.075 a minute for ten minutes.
        $this->assertEqualsWithDelta(-0.75, (float) $this->character->fresh()->mood, 0.05);

        $this->character->forceFill(['study_progress' => 99.9, 'mood' => 0])->save();
        $this->home->startSession($this->character->fresh(), Action::Study);
        $this->home->settleSession($this->character->fresh(), now()->addMinute());

        // +10 for the point, -0.075 for the minute.
        $this->assertEqualsWithDelta(9.925, (float) $this->character->fresh()->mood, 0.05);
    }

    public function test_resting_lifts_a_low_mood_quickly_and_a_good_one_hardly(): void
    {
        $this->character->home()->forceFill(['rest_rate' => 1, 'actions' => ['rest']])->save();

        foreach ([[-20, -19.75], [20, 20.025]] as [$mood, $expected]) {
            $this->character->forceFill(['current_fatigue' => 10, 'mood' => $mood])->save();
            $this->home->startRest($this->character->fresh());

            $this->home->settleRest($this->character->fresh(), now()->addMinutes(5), stop: true);

            // Five points of fatigue eased: 0.05 each below zero, 0.005 each above.
            $this->assertEqualsWithDelta($expected, (float) $this->character->fresh()->mood, 0.01);
        }
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

    /** Fifteen gains from the gym or the books unlock Roy Natty or Mentat; the two are counted apart. */
    public function test_gains_count_towards_roy_natty_and_mentat(): void
    {
        $achievements = new Achievements;
        $gain = function (Action $action): void {
            $this->character->forceFill([$action->value.'_progress' => 99.9])->save();
            $this->home->startSession($this->character, $action);
            $this->home->settleSession($this->character, now()->addMinute());
            CityActivity::query()->delete();
            $this->character->forceFill(['current_fatigue' => 0])->save();
        };

        foreach (range(1, 14) as $ignored) {
            $gain(Action::Workout);
        }

        $this->assertSame(14, $achievements->progressFor($this->character, Metric::WorkoutGains));
        $this->assertSame(0, $achievements->progressFor($this->character, Metric::StudyGains));
        $this->assertNotContains(24, $achievements->unlockedFor($this->character->user));

        $gain(Action::Workout);

        $this->assertContains(24, $achievements->unlockedFor($this->character->user));
        $this->assertNotContains(25, $achievements->unlockedFor($this->character->user));
    }

    public function test_the_minute_clock_credits_a_session_nobody_is_looking_at(): void
    {
        $this->home->startSession($this->character, Action::Study);

        $this->travel(10)->minutes();
        $this->artisan('characters:regenerate')->assertSuccessful();

        // 10 minutes at 2 a minute, less the clock's own ease: well above what a bare ease could leave.
        $this->assertGreaterThan(10, (float) $this->character->fresh()->current_fatigue);
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
        // The allowance is per calendar day, and three hours on must not cross midnight.
        $this->travelTo(now()->startOfDay()->addHours(8));
        $this->assertTrue($this->home->startSession($this->character, Action::Simstim));

        $this->home->settleSession($this->character, now()->addHours(3));

        $fresh = $this->character->fresh();
        $this->assertSame(7200, $fresh->simstim_seconds);
        $this->assertEqualsWithDelta(48.0, (float) $fresh->mood, 0.1);
        $this->assertSame(Refusal::NothingToDo, $this->home->startSession($fresh, Action::Simstim));
    }
}
