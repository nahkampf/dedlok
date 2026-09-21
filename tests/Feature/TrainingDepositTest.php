<?php

namespace Tests\Feature;

use App\Achievements\Achievements;
use App\Achievements\Metric;
use App\Events\SkillLevelReached;
use App\Events\TrainingEntryCompleted;
use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\Skill;
use App\Models\TrainingQueueEntry;
use App\Skills\DepositResult;
use App\Skills\SkillProgression;
use App\Skills\TrainingDeposit;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\InjectsSkills;
use Tests\TestCase;

/**
 * The deposit is the only writer of skill_points, and the one piece of the
 * training system that has to be right about time. Its central claim is that
 * it is NOT a per-minute tick: a single run after an outage must produce what
 * a run every minute would have.
 */
class TrainingDepositTest extends TestCase
{
    use InjectsSkills, RefreshDatabase;

    /** Rate at attributes 10/10: 60 * (0.121 * 10 + 0.0762 * 10). */
    private const RATE = 118.32;

    private Character $character;

    private Skill $hacking;

    private Skill $cryptography;

    private TrainingDeposit $deposit;

    private SkillProgression $progression;

    private CarbonImmutable $start;

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = $this->characterWithAttributes(10);

        // Rank 16 is the slowest tier, so a level takes hours rather than
        // seconds — which is what makes the gap arithmetic observable.
        $this->hacking = Skill::factory()->rank(16)
            ->governedBy('intellect', 'memory')
            ->create(['slug' => 'hacking']);

        $this->cryptography = Skill::factory()->rank(16)
            ->governedBy('intellect', 'memory')
            ->create(['slug' => 'cryptography']);

        $this->deposit = TrainingDeposit::make();
        $this->progression = SkillProgression::fromConfig();
        $this->start = CarbonImmutable::parse('2087-03-04 12:00:00');
    }

    private function characterWithAttributes(int $value): Character
    {
        // Archetype 3 (sleuth) has no perks, so resolved attributes equal the
        // stored columns and the rate is exactly the formula.
        return Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, $value),
            ['archetype' => 3],
        ));
    }

    /**
     * Queue an entry that is already training. A null clock is what an entry
     * looks like before its first deposit, so it needs its own helper rather
     * than a null default that would be indistinguishable from "unset".
     */
    private function queue(Skill $skill, int $level, int $position = 1, ?Character $character = null): TrainingQueueEntry
    {
        return $this->queueRow($skill, $level, $position, $this->start, $character);
    }

    private function queueUnstarted(Skill $skill, int $level, int $position = 1, ?Character $character = null): TrainingQueueEntry
    {
        return $this->queueRow($skill, $level, $position, null, $character);
    }

    private function queueRow(Skill $skill, int $level, int $position, ?CarbonImmutable $clock, ?Character $character): TrainingQueueEntry
    {
        $character ??= $this->character;

        // Queuing presupposes the skill was injected — the deposit will not
        // create a progress row, and refuses outright without one.
        if (! $character->fresh()->hasInjected($skill)) {
            $this->inject($character, $skill);
        }

        return TrainingQueueEntry::create([
            'character_id' => $character->id,
            'skill_id' => $skill->id,
            'target_level' => $level,
            'position' => $position,
            'last_deposited_at' => $clock,
        ]);
    }

    private function advance(int $minutes, ?Character $character = null): DepositResult
    {
        $character ??= $this->character;

        return $this->deposit->advance($character->fresh(), $this->start->addMinutes($minutes));
    }

    private function points(?Skill $skill = null, ?Character $character = null): int
    {
        $row = CharacterSkill::query()
            ->where('character_id', ($character ?? $this->character)->id)
            ->where('skill_id', ($skill ?? $this->hacking)->id)
            ->first();

        return (int) ($row?->skill_points ?? 0);
    }

    // ---- the fixture ------------------------------------------------------

    public function test_the_fixture_trains_at_the_configured_rate(): void
    {
        $this->assertEqualsWithDelta(
            self::RATE,
            $this->character->trainingRateFor($this->hacking),
            0.0001,
            'the rest of this file reasons in points per minute at this rate'
        );
    }

    // ---- the clock --------------------------------------------------------

    public function test_a_fresh_entry_only_starts_the_clock(): void
    {
        $entry = $this->queueUnstarted($this->hacking, 1);

        $result = $this->advance(10);

        $this->assertSame(0, $result->pointsAwarded, 'nothing accrued before training began');
        $this->assertSame(0, $this->points());
        $this->assertTrue($this->start->addMinutes(10)->equalTo($entry->fresh()->last_deposited_at));
    }

    public function test_it_credits_the_elapsed_time(): void
    {
        $this->queue($this->hacking, 1);

        $result = $this->advance(10);

        $this->assertSame((int) floor(10 * self::RATE), $result->pointsAwarded);
        $this->assertSame((int) floor(10 * self::RATE), $this->points());
    }

    public function test_a_second_run_in_the_same_minute_is_a_no_op(): void
    {
        $this->queue($this->hacking, 1);

        $this->advance(10);
        $after = $this->points();

        $again = $this->advance(10);

        $this->assertSame(0, $again->pointsAwarded);
        $this->assertSame($after, $this->points());
    }

    public function test_the_clock_never_runs_ahead_of_the_points_awarded(): void
    {
        $entry = $this->queue($this->hacking, 1);

        $this->advance(10);

        $credited = $this->points() / self::RATE;   // minutes actually paid for
        $moved = $this->start->diffInSeconds($entry->fresh()->last_deposited_at) / 60;

        $this->assertLessThanOrEqual(
            $credited + 1 / 60,
            $moved,
            'time may be carried forward, never consumed without payment'
        );
    }

    public function test_the_leftover_fraction_is_carried_into_the_next_run(): void
    {
        $this->queue($this->hacking, 1);

        // Ten one-minute runs must not lose the 0.32 of a point that each
        // minute earns beyond a whole one.
        for ($minute = 1; $minute <= 10; $minute++) {
            $this->advance($minute);
        }

        $this->assertSame(
            (int) floor(10 * self::RATE),
            $this->points(),
            'ten separate minutes must pay the same as one ten-minute run'
        );
    }

    // ---- the headline claim ----------------------------------------------

    public function test_one_run_after_an_outage_matches_running_every_minute(): void
    {
        $steady = $this->characterWithAttributes(10);

        $this->queue($this->hacking, 3);
        $this->queue($this->hacking, 3, character: $steady);

        // Eight hours down, then one catch-up pass.
        $this->advance(480);

        // The same eight hours, one minute at a time.
        for ($minute = 1; $minute <= 480; $minute++) {
            $this->advance($minute, $steady);
        }

        $this->assertSame(
            $this->points($this->hacking, $steady),
            $this->points(),
            'the schedule is a trigger, not the source of truth'
        );
    }

    // ---- levels -----------------------------------------------------------

    public function test_each_level_crossed_is_reported_once_and_in_order(): void
    {
        Event::fake([SkillLevelReached::class]);

        $this->queue($this->hacking, 3);

        // Level 2 of a rank 16 skill is 19,360 points: about 164 minutes.
        $minutes = (int) ceil($this->hacking->pointsForLevel(2) / self::RATE) + 1;
        $result = $this->advance($minutes);

        $this->assertSame(
            [['hacking', 1], ['hacking', 2]],
            $result->levelsReached,
            'a long outage must not collapse two breakpoints into one'
        );

        Event::assertDispatchedTimes(SkillLevelReached::class, 2);
        Event::assertDispatched(SkillLevelReached::class, fn ($event) => $event->level === 1);
        Event::assertDispatched(SkillLevelReached::class, fn ($event) => $event->level === 2);
    }

    public function test_the_stored_level_follows_the_points(): void
    {
        $this->queue($this->hacking, 2);

        $minutes = (int) ceil($this->hacking->pointsForLevel(1) / self::RATE) + 1;
        $this->advance($minutes);

        $row = CharacterSkill::where('skill_id', $this->hacking->id)->first();

        $this->assertSame(1, $row->attained_level);
        $this->assertSame($row->derivedLevel(), $row->attained_level, 'no drift between the two');
    }

    // ---- completion and handover -----------------------------------------

    public function test_a_finished_entry_leaves_the_queue_and_promotes_the_next(): void
    {
        Event::fake([TrainingEntryCompleted::class]);

        $first = $this->queue($this->hacking, 1, 1);
        $second = $this->queueUnstarted($this->cryptography, 1, 2);

        $minutes = (int) ceil($this->hacking->pointsForLevel(1) / self::RATE) + 1;
        $result = $this->advance($minutes);

        $this->assertSame([['hacking', 1]], $result->entriesCompleted);
        $this->assertNull($first->fresh(), 'a completed entry is deleted, not flagged');
        $this->assertSame(1, $second->fresh()->position, 'the next entry is promoted');

        Event::assertDispatched(
            TrainingEntryCompleted::class,
            fn ($event) => $event->skill->slug === 'hacking'
                && $event->targetLevel === 1
                && $event->queueEmpty === false
        );
    }

    public function test_the_successor_inherits_the_completion_moment(): void
    {
        $this->queue($this->hacking, 1, 1);
        $this->queueUnstarted($this->cryptography, 1, 2);

        // Overshoot the first entry by an hour: the surplus belongs to the
        // second, not to nobody.
        $minutes = (int) ceil($this->hacking->pointsForLevel(1) / self::RATE) + 60;
        $this->advance($minutes);

        $this->assertGreaterThan(
            0,
            $this->points($this->cryptography),
            'the remainder of the gap keeps accruing across the handover'
        );
    }

    public function test_a_lone_entry_banks_the_minute_it_crossed_in(): void
    {
        // The threshold falls part-way through a minute. That minute is
        // credited whole rather than truncated on the threshold, because the
        // player trained all of it.
        $this->queue($this->hacking, 1);

        $needed = $this->hacking->pointsForLevel(1);
        $crossingMinute = (int) ceil($needed / self::RATE);

        $this->advance($crossingMinute);

        $this->assertGreaterThan($needed, $this->points(), 'the surplus was thrown away');
        $this->assertSame((int) floor($crossingMinute * self::RATE), $this->points());
        $this->assertSame(0, $this->character->trainingQueue()->count(), 'the entry still completes');
    }

    public function test_the_surplus_stops_at_the_crossing_minute(): void
    {
        // The distinction is invisible minute by minute and is everything on a
        // catch-up run: an hour of `now` past the threshold must not become an
        // hour of training for a queue that emptied in its first minute.
        $this->queue($this->hacking, 1);

        $needed = $this->hacking->pointsForLevel(1);
        $crossingMinute = (int) ceil($needed / self::RATE);

        $this->advance($crossingMinute + 60);

        $this->assertSame(
            (int) floor($crossingMinute * self::RATE),
            $this->points(),
            'the hour after the queue emptied was credited'
        );
    }

    public function test_a_successor_still_takes_the_surplus_rather_than_the_finished_skill(): void
    {
        // The complement of the test above: with something else to train,
        // stopping dead on the threshold IS the point.
        $this->queue($this->hacking, 1, 1);
        $this->queueUnstarted($this->cryptography, 1, 2);

        $needed = $this->hacking->pointsForLevel(1);
        $this->advance((int) ceil($needed / self::RATE) + 5);

        $this->assertSame($needed, $this->points(), 'the finished skill took more than it needed');
        $this->assertGreaterThan(0, $this->points($this->cryptography));
    }

    public function test_the_crossing_minute_never_carries_a_skill_past_its_cap(): void
    {
        // Level 5 IS the cap, so this is the one target whose crossing minute
        // would spill over it. It must land exactly on the ceiling.
        $this->queue($this->hacking, 5);

        $cap = $this->hacking->pointsCap();
        $this->advance((int) ceil($cap / self::RATE) + 10);

        $this->assertSame($cap, $this->points(), 'the crossing minute spilled past the cap');
        $this->assertSame(5, (int) CharacterSkill::query()
            ->where('character_id', $this->character->id)
            ->where('skill_id', $this->hacking->id)
            ->value('attained_level'));

        // Reaching the ceiling is what recounts how many stand at it.
        $this->assertSame(1, (new Achievements)->progressFor($this->character, Metric::SkillsAtMaxLevel));
    }

    public function test_the_last_entry_reports_an_empty_queue(): void
    {
        Event::fake([TrainingEntryCompleted::class]);

        $this->queue($this->hacking, 1);

        $minutes = (int) ceil($this->hacking->pointsForLevel(1) / self::RATE) + 1;
        $this->advance($minutes);

        Event::assertDispatched(
            TrainingEntryCompleted::class,
            fn ($event) => $event->queueEmpty === true
        );
        $this->assertSame(0, $this->character->trainingQueue()->count());
    }

    // ---- the ceiling ------------------------------------------------------

    public function test_points_never_pass_the_cap(): void
    {
        $cap = $this->hacking->pointsCap();

        $this->inject($this->character, $this->hacking, $cap - 100, 4);

        $this->queue($this->hacking, 5);

        $this->advance(60 * 24 * 30);   // a month of overspill

        $this->assertSame($cap, $this->points(), 'accrual stops at level 5; overspill is discarded');
    }

    // ---- the stall --------------------------------------------------------

    public function test_zero_attributes_stall_rather_than_loop(): void
    {
        $useless = $this->characterWithAttributes(0);
        $entry = $this->queue($this->hacking, 1, character: $useless);

        $result = $this->advance(600, $useless);

        $this->assertTrue($result->stalled);
        $this->assertSame(0, $result->pointsAwarded);
        $this->assertSame(0, $this->points($this->hacking, $useless));
        $this->assertSame('0.00', $entry->fresh()->sp_per_minute, 'the stall is recorded, not hidden');
        $this->assertTrue(
            $this->start->equalTo($entry->fresh()->last_deposited_at),
            'the clock stays put, so a later implant credits the whole wait'
        );
    }

    public function test_the_rate_used_is_written_back_to_the_entry(): void
    {
        $entry = $this->queue($this->hacking, 1);

        $this->advance(10);

        $this->assertSame('118.32', $entry->fresh()->sp_per_minute);
    }

    // ---- isolation --------------------------------------------------------

    public function test_it_touches_only_the_character_it_is_given(): void
    {
        $other = $this->characterWithAttributes(10);

        $this->queue($this->hacking, 1);
        $this->queue($this->hacking, 1, character: $other);

        $this->advance(10);

        $this->assertGreaterThan(0, $this->points());
        $this->assertSame(0, $this->points($this->hacking, $other));
    }
}
