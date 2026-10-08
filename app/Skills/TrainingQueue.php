<?php

declare(strict_types=1);

namespace App\Skills;

use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\Skill;
use App\Models\TrainingQueueEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of a training queue entry a PLAYER asked for.
 *
 * TrainingDeposit writes these rows too — it retires a finished entry and
 * renumbers what is left — but that is the clock moving rather than somebody
 * deciding. Every decision goes through here, which is what keeps the rules
 * below in one place: SkillInjector is the gate in front of the sheet, and
 * this is the gate in front of the queue.
 *
 * ## An entry is a (skill, level) pair, so queuing is always "the next one up"
 *
 * The player picks a skill, never a level. What they get is the next level
 * they have not reached AND have not already queued, which is the only entry
 * the validator would accept anyway: targets have to be consecutive or the
 * levels in between never train. Pressing the same button five times is how a
 * skill gets taken to level 5, and there is nothing else it could sensibly
 * mean.
 *
 * ## Removing one is allowed to break the rest, on purpose
 *
 * Dropping "Hacking I" out from under "Hacking II" leaves a queue the
 * validator complains about, and it is still the right thing to allow: the
 * alternative is a delete that silently takes three other entries with it.
 * The warnings are on the screen, and the player can see what they did.
 */
final class TrainingQueue
{
    /** How many entries a character may line up. */
    public const LIMIT = 5;

    /** Supporters line up as many as they like (null is no limit); everybody else gets LIMIT. */
    public static function limitFor(Character $character): ?int
    {
        return $character->user?->isSupporter() ? null : self::LIMIT;
    }

    public function __construct(
        private readonly SkillProgression $progression,
        private readonly TrainingDeposit $deposit,
    ) {}

    public static function make(): self
    {
        return new self(SkillProgression::fromConfig(), TrainingDeposit::make());
    }

    /**
     * Put the next level of a skill at the back of the queue.
     *
     * Locked for the duration, because two taps on the same button would
     * otherwise compute the same target level twice and collide on the unique
     * (character, skill, target_level) index — a 500 where a refusal belongs.
     */
    public function enqueue(Character $character, Skill $skill): TrainingQueueEntry|QueueRefusal
    {
        return DB::transaction(function () use ($character, $skill): TrainingQueueEntry|QueueRefusal {
            $entries = $character->trainingQueue()->lockForUpdate()->get();

            $limit = self::limitFor($character);

            if ($limit !== null && $entries->count() >= $limit) {
                return QueueRefusal::QueueFull;
            }

            // A character_skills row is what injecting the imprint creates, and
            // training can never create one — TrainingDeposit refuses and logs
            // rather than granting the skill. So the absence of a row here is
            // the whole gate, and reading its level would be the wrong test:
            // level 0 is a real state.
            $progress = CharacterSkill::query()
                ->where('character_id', $character->id)
                ->where('skill_id', $skill->id)
                ->first();

            if ($progress === null) {
                return QueueRefusal::NotInjected;
            }

            $queued = (int) $entries->where('skill_id', $skill->id)->max('target_level');
            $target = max((int) $progress->attained_level, $queued) + 1;

            if ($target > $this->progression->maxLevel()) {
                return QueueRefusal::AlreadyMaxed;
            }

            return TrainingQueueEntry::create([
                'character_id' => $character->id,
                'skill_id' => $skill->id,
                'target_level' => $target,
                // The clock is left null. An entry that is not training yet has
                // no clock to keep, and TrainingDeposit starts one the first
                // time it finds this entry at position 1.
                'position' => $entries->count() + 1,
            ]);
        });
    }

    /**
     * Put the queue in a new order: `$ids` are ALL the character's entry ids, first to train first.
     *
     * REFUSED when the new order has a problem the current one does not (`introducedProblems()`):
     * Hacking II ahead of Hacking I, or a skill ahead of its prerequisite. A problem the queue already
     * had is not held against a reordering that leaves it no worse, so a queue the player was left
     * with by removing something can still be tidied.
     *
     * THE DEPOSIT RUNS FIRST, for `remove()`'s reason: whatever trains now has accrued since the last
     * minute. The entry that stops being first LOSES ITS CLOCK (a stale one would credit the time it
     * spent waiting the next time it came round), and the one that becomes first starts a clock at the
     * minute the deposit just banked to.
     */
    public function reorder(Character $character, array $ids): true|ReorderRefusal
    {
        $this->deposit->advance($character);

        return DB::transaction(function () use ($character, $ids): true|ReorderRefusal {
            $entries = $character->trainingQueue()->lockForUpdate()->with('skill')->get();

            $ids = array_map('intval', array_values($ids));
            $held = $entries->pluck('id')->map(fn ($id): int => (int) $id)->all();

            if (count($ids) !== count($held) || array_diff($ids, $held) !== [] || count(array_unique($ids)) !== count($ids)) {
                return ReorderRefusal::QueueChanged;
            }

            if ($ids === $held) {
                return true;
            }

            if ($this->newProblems($character, $entries, $ids) !== []) {
                return ReorderRefusal::BreaksRequirement;
            }

            $firstBefore = (int) $entries->first()->id;

            // Two passes: (character_id, position) is unique, so rows cannot swap places in one. The parking
            // spot has to fit the column, which is a tinyint unsigned (255): 100 clears a queue of five.
            foreach ($entries as $entry) {
                $entry->forceFill(['position' => $entry->position + 100])->save();
            }

            foreach ($ids as $index => $id) {
                $entry = $entries->firstWhere('id', $id);
                $attributes = ['position' => $index + 1];

                if ($ids[0] !== $firstBefore) {
                    if ($index === 0) {
                        $attributes['last_deposited_at'] = now()->startOfMinute();
                    } elseif ($id === $firstBefore) {
                        $attributes['last_deposited_at'] = null;
                    }
                }

                $entry->forceFill($attributes)->save();
            }

            return true;
        });
    }

    /**
     * What a reordering would say that the queue does not say now: empty when it is safe.
     *
     * @param  list<int>  $ids
     * @return list<string>
     */
    public function introducedProblems(Character $character, array $ids): array
    {
        return $this->newProblems($character, $character->trainingQueue()->with('skill')->get(), array_map('intval', $ids));
    }

    /**
     * @param  Collection<int, TrainingQueueEntry>  $entries  the current queue
     * @param  list<int>  $ids  the proposed order
     * @return list<string>
     */
    private function newProblems(Character $character, Collection $entries, array $ids): array
    {
        $validator = new TrainingQueueValidator;

        // A problem's position, and how far short a prerequisite is, are part of its sentence, and moving an entry
        // moves both: compare the problems without them, so one the queue already had is the same problem.
        $plain = static fn (array $problems): array => array_map(
            static fn (string $p): string => (string) preg_replace(['/position \d+/', '/only \d+ is/'], ['position', 'only N is'], $p),
            $problems,
        );

        $proposed = collect($ids)->map(function (int $id, int $index) use ($entries): TrainingQueueEntry {
            $copy = $entries->firstWhere('id', $id)?->replicate() ?? new TrainingQueueEntry;
            $copy->setRelation('skill', $entries->firstWhere('id', $id)?->skill);
            $copy->position = $index + 1;

            return $copy;
        });

        return array_values(array_diff(
            $plain($validator->problemsForEntries($character, $proposed)),
            $plain($validator->problemsForEntries($character, $entries)),
        ));
    }

    /**
     * Take an entry out and close the gap behind it.
     *
     * THE DEPOSIT RUNS FIRST, always. Removing the entry at position 1 throws
     * away its clock, and with it whatever fraction of a point has accrued
     * since the last minute — banking before the write is the difference
     * between that being nothing and being up to a minute of training. It is
     * done here rather than in the controller so no future caller can forget.
     */
    public function remove(Character $character, TrainingQueueEntry $entry): void
    {
        $this->deposit->advance($character);

        DB::transaction(function () use ($character, $entry): void {
            // The deposit above may have finished and deleted this very entry.
            // Then it is already gone and the renumbering below is all that is
            // left to do — which the deposit did too, so this is a no-op.
            TrainingQueueEntry::whereKey($entry->getKey())
                ->where('character_id', $character->id)
                ->delete();

            // Ascending, so each row moves into a position the row before it
            // has already vacated: (character_id, position) is unique, and
            // renumbering in any other order collides with itself.
            foreach ($character->trainingQueue()->get() as $index => $row) {
                $row->forceFill(['position' => $index + 1])->save();
            }
        });
    }
}
