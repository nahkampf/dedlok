<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterModifier;
use App\Models\CharacterSkill;
use App\Models\Skill;
use App\Models\TrainingQueueEntry;
use App\Skills\TrainingDeposit;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * A modifier that starts or stops inside a training window.
 *
 * This is the case time-aware resolution exists for. A deposit covering three
 * hours during which a stim wore off has to credit the bonus for the hour that
 * had it and not for the two that did not — and the mirror, one taken partway
 * through, must not be credited for the time before it was taken.
 *
 * Everything here is measured against two poles: the same window with NO bonus
 * at all, and the same window with the bonus live throughout. A partial bonus
 * has to land strictly between them. That is the only shape of assertion that
 * can tell a working split from a rate that simply got applied to everything.
 *
 * Getting this wrong is invisible in play: the number is merely a little too
 * big, on a screen with nothing to compare it against.
 */
final class TimedModifierTrainingTest extends TestCase
{
    use RefreshDatabase;

    private Skill $skill;

    private CarbonImmutable $now;

    private CarbonImmutable $start;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::now();
        $this->start = $this->now->subHours(3);

        // Rank 16 is the slowest tier, so three hours is a long grind rather
        // than an instant level — and the target is the level 5 ceiling, so
        // nothing here completes and the award stays proportional to the rate.
        $this->skill = Skill::factory()->rank(16)
            ->governedBy('intellect', 'memory')
            ->create(['slug' => 'timed_modifier_subject']);

        // A large, obvious bonus so the arithmetic is legible.
        Config::set('effects.stim', [
            'label' => 'Stim',
            'stacking' => 'sum',
            'modifiers' => [
                ['op' => 'add', 'amount' => 60, 'target' => ['id' => 'intellect']],
            ],
        ]);
    }

    /**
     * Train one fresh character across the window, and say what they earned.
     *
     * $carry receives the character and may write a modifier with whatever
     * interval the case is about.
     */
    private function earnedWith(?callable $carry = null): int
    {
        $character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['archetype' => 3],
        ));

        CharacterSkill::query()->create([
            'character_id' => $character->id,
            'skill_id' => $this->skill->id,
            'skill_points' => 0,
            'attained_level' => 0,
        ]);

        TrainingQueueEntry::query()->create([
            'character_id' => $character->id,
            'skill_id' => $this->skill->id,
            'target_level' => 5,
            'position' => 1,
            'last_deposited_at' => $this->start,
            'sp_per_minute' => 0,
        ]);

        if ($carry !== null) {
            $carry($character);
        }

        TrainingDeposit::make()->advance($character->fresh(), $this->now);

        return CharacterSkill::query()
            ->where('character_id', $character->id)
            ->where('skill_id', $this->skill->id)
            ->value('skill_points');
    }

    /** Write a stim onto a character over an explicit interval. */
    private function stim(Character $character, ?CarbonImmutable $from, ?CarbonImmutable $until): void
    {
        $row = CharacterModifier::query()->create([
            'character_id' => $character->id,
            'definition_key' => 'stim',
            'expires_at' => $until,
        ]);

        // created_at is the applied-at moment, and Eloquent has just set it to
        // now — so the interval's start is written afterwards.
        CharacterModifier::query()->whereKey($row->getKey())
            ->update(['created_at' => $from ?? $this->start->subDay()]);
    }

    public function test_the_two_poles_are_far_apart(): void
    {
        $plain = $this->earnedWith();
        $full = $this->earnedWith(fn (Character $c) => $this->stim($c, null, null));

        $this->assertGreaterThan(0, $plain);
        $this->assertGreaterThan(
            $plain * 2,
            $full,
            'a permanent bonus of this size must dominate the window, or nothing below can be measured',
        );
    }

    /** Live for the first hour of three, then gone. */
    public function test_a_bonus_that_expires_mid_window_counts_only_while_it_was_live(): void
    {
        $plain = $this->earnedWith();
        $full = $this->earnedWith(fn (Character $c) => $this->stim($c, null, null));

        $partial = $this->earnedWith(
            fn (Character $c) => $this->stim($c, null, $this->start->addHour())
        );

        $this->assertGreaterThan($plain, $partial, 'the hour it was live has to count');
        $this->assertLessThan($full, $partial, 'and the two hours it was not must not');
    }

    /** Taken two hours in, still going at the end. */
    public function test_a_bonus_taken_mid_window_is_not_credited_for_the_time_before_it(): void
    {
        $plain = $this->earnedWith();
        $full = $this->earnedWith(fn (Character $c) => $this->stim($c, null, null));

        $partial = $this->earnedWith(
            fn (Character $c) => $this->stim($c, $this->start->addHours(2), $this->now->addHour())
        );

        $this->assertGreaterThan($plain, $partial, 'the hour it was live counts');
        $this->assertLessThan($full, $partial, 'the two hours before it was taken do not');
    }

    /**
     * The regression this all guards. Resolving at deposit time would value a
     * bonus that had already worn off at nothing, however long it had been
     * live inside the window.
     */
    public function test_a_bonus_that_expired_before_the_deposit_ran_still_counts_for_its_time(): void
    {
        $plain = $this->earnedWith();

        $partial = $this->earnedWith(
            fn (Character $c) => $this->stim($c, null, $this->start->addHours(2))
        );

        $this->assertGreaterThan(
            $plain,
            $partial,
            'expired by the time we looked, but live for two of the three hours',
        );
    }

    /** And the mirror: one taken after the window ended is worth nothing in it. */
    public function test_a_bonus_taken_after_the_window_is_not_credited_at_all(): void
    {
        $plain = $this->earnedWith();

        $later = $this->earnedWith(
            fn (Character $c) => $this->stim($c, $this->now->addMinute(), null)
        );

        $this->assertSame($plain, $later);
    }
}
