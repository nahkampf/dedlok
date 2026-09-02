<?php

declare(strict_types=1);

namespace App\Skills;

use App\Events\SkillLevelReached;
use App\Events\TrainingEntryCompleted;
use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\TrainingQueueEntry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Credits training time to a character's queue.
 *
 * Called every minute by the scheduler, but it is NOT a per-minute tick: it
 * advances from the stored clock to now in one pass, so a run after an outage
 * produces exactly what a run every minute would have.
 *
 * The rate is piecewise-constant, and the pieces are knowable in advance. No
 * player acts during an outage, so the only things that can change mid-gap are
 * time-driven: a modifier expiring, or a skill completing. Both have computable
 * timestamps, so the gap is split at those boundaries and each segment is
 * computed in bulk — O(events), not O(minutes). An eight-hour outage is a
 * handful of steps, not 480.
 *
 * Fractional points are never discarded. A segment awards whole points and
 * advances the clock only by the time those points actually cost, so the
 * leftover sub-point time carries into the next run instead of being rounded
 * away 1,440 times a day. That is why the clock is stored to the microsecond:
 * at whole seconds the carry is itself rounded, and the loss it was meant to
 * prevent comes back.
 */
final class TrainingDeposit
{
    /** Stops a pathological loop from running forever. */
    private const MAX_SEGMENTS = 500;

    /**
     * The clock is advanced in microseconds, not seconds.
     *
     * A deposit moves the clock by exactly the time its points cost, and that
     * time is almost never a whole second. Rounding it to one loses the
     * remainder every run — a third of a point a minute at a typical rate,
     * which is 1,440 chances a day to lose it. See the migration that widened
     * training_queue.last_deposited_at.
     */
    private const MICROSECONDS_PER_MINUTE = 60_000_000;

    public function __construct(
        private readonly SkillProgression $progression,
        private readonly TrainingRate $rate,
    ) {}

    public static function make(): self
    {
        return new self(SkillProgression::fromConfig(), TrainingRate::fromConfig());
    }

    public function advance(Character $character, ?CarbonInterface $now = null): DepositResult
    {
        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now())->startOfMinute();

        return DB::transaction(function () use ($character, $now): DepositResult {
            $result = new DepositResult;
            $segments = 0;

            while ($segments++ < self::MAX_SEGMENTS) {
                $entry = $character->trainingQueue()->with('skill')->active()->first();

                if ($entry === null) {
                    break;   // nothing queued
                }

                // A queue entry with no clock has only just started training.
                if ($entry->last_deposited_at === null) {
                    $entry->forceFill(['last_deposited_at' => $now])->save();

                    break;
                }

                $cursor = CarbonImmutable::instance($entry->last_deposited_at);

                if ($cursor >= $now) {
                    break;   // already credited for this minute; a re-run is a no-op
                }

                $advanced = $this->advanceOneSegment($character, $entry, $cursor, $now, $result);

                if (! $advanced) {
                    break;   // stalled: no rate, or not enough time for a whole point
                }
            }

            return $result;
        });
    }

    /**
     * Credit one segment of constant rate, and handle whatever ended it.
     *
     * Returns false when nothing more can be credited, so the caller stops
     * rather than spinning.
     */
    private function advanceOneSegment(
        Character $character,
        TrainingQueueEntry $entry,
        CarbonImmutable $cursor,
        CarbonImmutable $now,
        DepositResult $result,
    ): bool {
        $skill = $entry->skill;

        if ($skill === null) {
            return false;
        }

        $perMinute = $character->trainingRateFor($skill);

        if ($perMinute <= 0) {
            // Every governing attribute is zero. Training can never finish, so
            // stop rather than looping. The clock is left where it is.
            $result->stalled = true;
            $entry->forceFill(['sp_per_minute' => 0])->save();

            return false;
        }

        $progress = $this->progressRow($character, $entry);
        $cap = $this->progression->cap($skill->rank);

        if ($progress->skill_points >= $cap) {
            // Already at the level 5 ceiling; overspill is discarded.
            $this->completeEntry($character, $entry, $cursor, $result);

            return true;
        }

        $segmentEnd = $this->segmentEnd($character, $entry, $progress, $perMinute, $cursor, $now);
        $minutes = $cursor->diffInSeconds($segmentEnd) / 60;

        // Whole points only, with the leftover time carried forward.
        $award = (int) floor($minutes * $perMinute);

        if ($award <= 0) {
            $entry->forceFill(['sp_per_minute' => $perMinute])->save();

            return false;   // not yet a full point's worth of time
        }

        $award = min($award, $cap - $progress->skill_points);
        $earnedAt = $cursor->addMicroseconds((int) round(($award / $perMinute) * self::MICROSECONDS_PER_MINUTE));

        $before = $progress->attained_level;
        $progress->skill_points += $award;
        $progress->attained_level = $this->progression->levelFor($progress->skill_points, $skill->rank);
        $progress->save();

        $result->pointsAwarded += $award;

        // One event per level, in order — a long outage must not collapse three
        // breakpoints into a single "reached level 3".
        for ($level = $before + 1; $level <= $progress->attained_level; $level++) {
            SkillLevelReached::dispatch($character, $skill, $level, $earnedAt);
            $result->levelsReached[] = [$skill->slug, $level];
        }

        $entry->forceFill([
            'last_deposited_at' => $earnedAt,
            'sp_per_minute' => $perMinute,
        ])->save();

        if ($progress->attained_level >= $entry->target_level) {
            $this->completeEntry($character, $entry, $earnedAt, $result);
        }

        return true;
    }

    /**
     * Where this segment ends: whichever comes first of now, the moment the
     * entry completes, and the next change in rate.
     */
    private function segmentEnd(
        Character $character,
        TrainingQueueEntry $entry,
        CharacterSkill $progress,
        float $perMinute,
        CarbonImmutable $cursor,
        CarbonImmutable $now,
    ): CarbonImmutable {
        $end = $now;

        $target = $this->progression->threshold($entry->target_level, $entry->skill->rank);
        $needed = $target - $progress->skill_points;

        if ($needed > 0) {
            $completesAt = $cursor->addMicroseconds((int) ceil(($needed / $perMinute) * self::MICROSECONDS_PER_MINUTE));

            if ($completesAt < $end) {
                $end = $completesAt;
            }
        }

        foreach ($this->rateChangeBoundaries($character, $cursor, $end) as $boundary) {
            if ($boundary > $cursor && $boundary < $end) {
                $end = $boundary;
            }
        }

        return $end;
    }

    /**
     * Moments inside a window at which the training rate changes.
     *
     * Empty today: the only modifiers that exist are archetype perks, which are
     * permanent. This is where timed modifiers plug in — a drug expiring
     * mid-outage must split the gap, or its bonus is credited for the whole
     * period instead of the ten minutes it had left.
     *
     * @return list<CarbonImmutable>
     */
    private function rateChangeBoundaries(Character $character, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [];
    }

    private function progressRow(Character $character, TrainingQueueEntry $entry): CharacterSkill
    {
        return CharacterSkill::firstOrCreate(
            ['character_id' => $character->id, 'skill_id' => $entry->skill_id],
            ['skill_points' => 0, 'attained_level' => 0],
        );
    }

    /**
     * Retire a finished entry and hand the clock to the next one.
     *
     * The successor inherits the exact completion moment, so the remainder of
     * the gap keeps accruing instead of being lost in the handover.
     */
    private function completeEntry(
        Character $character,
        TrainingQueueEntry $entry,
        CarbonImmutable $completedAt,
        DepositResult $result,
    ): void {
        $skill = $entry->skill;
        $target = $entry->target_level;

        $entry->delete();

        $remaining = $character->trainingQueue()->orderBy('position')->get();

        foreach ($remaining as $index => $successor) {
            $successor->forceFill([
                'position' => $index + 1,
                'last_deposited_at' => $index === 0 ? $completedAt : $successor->last_deposited_at,
            ])->save();
        }

        $result->entriesCompleted[] = [$skill?->slug, $target];

        TrainingEntryCompleted::dispatch(
            $character, $skill, $target, $completedAt, $remaining->isEmpty()
        );
    }
}
