<?php

declare(strict_types=1);

namespace App\Skills;

use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\Skill;
use App\Models\TrainingQueueEntry;
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

            if ($entries->count() >= self::LIMIT) {
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
