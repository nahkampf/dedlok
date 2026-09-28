<?php

declare(strict_types=1);

namespace App\Skills;

use App\Achievements\Achievements;
use App\Achievements\Metric;
use App\Events\SkillLevelReached;
use App\Events\TrainingEntryCompleted;
use App\Models\Character;
use App\Models\CharacterModifier;
use App\Models\CharacterSkill;
use App\Models\TrainingQueueEntry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
    ) {}

    public static function make(): self
    {
        return new self(SkillProgression::fromConfig());
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

        // AS OF THE START OF THIS SEGMENT, not as of now. During a catch-up
        // the window may be hours long and the character's modifiers may have
        // changed inside it; segmentEnd() below cuts the segment at every one
        // of those changes, and this is what makes the cut mean something.
        $perMinute = $character->trainingRateFor($skill, $cursor);

        if ($perMinute <= 0) {
            // Every governing attribute is zero. Training can never finish, so
            // stop rather than looping. The clock is left where it is.
            $result->stalled = true;
            $entry->forceFill(['sp_per_minute' => 0])->save();

            return false;
        }

        $progress = $this->progressRow($character, $entry);

        if ($progress === null) {
            // Queued but never injected. skills:validate reports this and the
            // queue validator refuses to accept it, so reaching here means the
            // row was written around both — refuse rather than quietly grant
            // the skill by creating its progress row.
            Log::warning('Training queued for a skill that was never injected.', [
                'character' => $character->id,
                'skill' => $skill->slug,
            ]);

            $result->uninjected = true;

            return false;
        }

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

            /*
             * Not a whole point's worth of time — but WHY the segment is short
             * decides whether that means stop or step over.
             *
             * A segment that ran all the way to `now` is simply the player
             * arriving early: the clock stays put and the time is credited on
             * the next visit. That is the ordinary case and it must not move
             * the cursor, or the leftover minutes would be thrown away every
             * time anybody looked at the screen.
             *
             * A segment cut SHORT BY A RATE CHANGE is a different thing. The
             * sliver before the boundary is worth less than a point, and
             * stopping here would abandon the entire rest of the window — which
             * is exactly what happened the first time a timed modifier expired
             * mid-catch-up. Step over the boundary and let the next segment,
             * at its own rate, carry on.
             *
             * The cost is under one point per rate change, truncated rather
             * than carried, because the leftover time was earned at a rate that
             * no longer applies and crediting it at the new one would be a
             * different wrong answer.
             */
            if ($segmentEnd < $now) {
                $entry->forceFill(['last_deposited_at' => $segmentEnd])->save();

                return true;
            }

            return false;
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

        /*
         * A SKILL REACHING THE CEILING is the only thing that can change how
         * many are AT the ceiling, so the recount is asked here and nowhere
         * else — one COUNT on a level-up rather than a running total somebody
         * has to keep in step with every writer of `attained_level`.
         *
         * `SkillInjector::grant()` can also seat a skill at the cap without
         * passing through here. It is left alone on purpose: an archetype
         * granting twenty capped skills is not a loadout anybody will author,
         * and the first deposit after one recounts everything anyway.
         */
        if ($progress->attained_level > $before && $progress->attained_level >= $this->progression->maxLevel()) {
            (new Achievements)->reach($character, Metric::SkillsAtMaxLevel, CharacterSkill::query()
                ->where('character_id', $character->id)
                ->where('attained_level', '>=', $this->progression->maxLevel())
                ->count());
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
     * entry hands over, and the next change in rate.
     *
     * Reaching the target only ends the segment on the threshold itself when
     * there is SOMETHING ELSE TO TRAIN. With a successor waiting that is the
     * point — the surplus belongs to the next skill, and completeEntry hands
     * it the exact moment so none of it is lost.
     *
     * With an empty queue behind it the segment runs to the END OF THE MINUTE
     * THE THRESHOLD FELL IN, and no further. The minute a player is part-way
     * through is credited whole, so they are not paid for less time than they
     * trained; the minutes after it are not, because by then there was nothing
     * queued to train. That distinction is invisible while deposits run every
     * minute — the crossing minute IS the current one — and is the whole
     * behaviour during a catch-up run after an outage, where `now` may be
     * hours past the threshold.
     *
     * The rank's level-5 cap bounds it either way, enforced on the award
     * rather than as a moment computed here: a rate change inside the segment
     * would let such a moment drift off the real ceiling.
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

            // Only look at the queue when the answer could differ: an entry
            // that does not finish inside this window ends at `now` either
            // way, and the query would be wasted on every run of every
            // character still working toward a level.
            if ($completesAt < $end) {
                $end = $this->hasSuccessor($character, $entry)
                    ? $completesAt                          // hand over on the threshold
                    : min($end, $completesAt->ceilMinute()); // finish the minute, then stop
            }
        }

        foreach ($this->rateChangeBoundaries($character, $cursor, $end) as $boundary) {
            if ($boundary > $cursor && $boundary < $end) {
                $end = $boundary;
            }
        }

        return $end;
    }

    /** Is anything queued behind this entry? */
    private function hasSuccessor(Character $character, TrainingQueueEntry $entry): bool
    {
        return $character->trainingQueue()
            ->whereKeyNot($entry->getKey())
            ->exists();
    }

    /**
     * Moments inside a window at which the training rate changes.
     *
     * A modifier is live over an interval — applied until it expires — so both
     * ENDS of that interval are places the rate can move. A drug taken during
     * an outage starts helping when it was taken, and stops when it wears off;
     * crediting either across the whole window is wrong, in opposite
     * directions.
     *
     * Every boundary is returned, not only the ones that touch a training
     * attribute. Splitting where nothing changed costs one extra resolution
     * and computes the same rate twice; working out in advance whether a given
     * modifier could matter would mean this method knowing which stats feed a
     * training rate, which is the resolver's business and not its caller's.
     *
     * The caller takes the earliest boundary strictly inside the segment, so
     * order and duplicates do not matter here.
     *
     * @return list<CarbonImmutable>
     */
    private function rateChangeBoundaries(Character $character, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $moments = [];

        $rows = CharacterModifier::query()
            ->where('character_id', $character->getKey())
            ->where(function ($query) use ($from, $to): void {
                $query
                    ->whereBetween('created_at', [$from, $to])
                    ->orWhereBetween('expires_at', [$from, $to]);
            })
            ->get(['created_at', 'expires_at']);

        foreach ($rows as $row) {
            foreach ([$row->created_at, $row->expires_at] as $moment) {
                if ($moment !== null && $moment > $from && $moment < $to) {
                    $moments[] = CarbonImmutable::instance($moment);
                }
            }
        }

        return $moments;
    }

    /**
     * The character's progress row for this entry's skill, or null.
     *
     * It does NOT create one. A character_skills row means "this skill has
     * been injected", and the only thing allowed to create that is
     * App\Inventory\SkillInjector — spending the imprint is the gate in
     * front of training. A deposit that created the row on demand would let
     * anything that can write a queue entry skip the imprint entirely.
     */
    private function progressRow(Character $character, TrainingQueueEntry $entry): ?CharacterSkill
    {
        return CharacterSkill::query()
            ->where('character_id', $character->id)
            ->where('skill_id', $entry->skill_id)
            ->first();
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
