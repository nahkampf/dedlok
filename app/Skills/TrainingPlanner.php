<?php

declare(strict_types=1);

namespace App\Skills;

use App\Models\Character;
use App\Models\Skill;

/**
 * Working backwards from a skill somebody WANTS to the entries that reach it.
 *
 * The queue is deliberately dumb: an entry is one level of one skill, and
 * `TrainingQueue::enqueue()` always means "the next one up". That is the right
 * shape for the writer and a miserable shape for a player who has just found
 * out that Codebreaking needs Hacking III and Cryptography V — nine presses in
 * an order they have to work out themselves, with the screen only telling them
 * afterwards which ones will stall.
 *
 * So this decides the ORDER and the COUNT, and nothing else. It returns one
 * `Skill` per level that has to be trained, repeated, in the order they have to
 * go in — and the caller feeds them to `enqueue()` one at a time. That is what
 * keeps this from being a second writer with its own opinions: every rule about
 * what may go into a queue still lives in exactly one place, and a plan that
 * names something the queue would refuse gets refused by the queue.
 *
 * ## Prerequisites first, then the skill's own levels
 *
 * A prerequisite is per SKILL rather than per level — `skill_prerequisites`
 * has no "at level N of mine" column — so everything a skill requires has to
 * stand before its first level, and nothing has to interleave. That is exactly
 * the property `TrainingQueueValidator` checks by walking a queue forward, so a
 * plan can be verified by the thing that judges a hand-built one.
 *
 * ## It plans from where the queue will HAVE left off
 *
 * A level already reached is not planned, and neither is one already queued:
 * the plan is what to add, not what to do. So planning the same thing twice
 * adds nothing the second time, which is the only sensible reading of pressing
 * it again.
 *
 * ## What it does NOT do is check whether a skill can be trained at all
 *
 * A book that has never been spent is a hard gate — `SkillInjector` is the only
 * thing that opens it, and `enqueue()` refuses `NotInjected` for everything
 * else. A plan that walks through a skill the character does not own is
 * therefore a plan that stops at it, and being TOLD which book is missing is
 * the whole value of asking. Silently planning around it would describe a route
 * that does not exist.
 */
final class TrainingPlanner
{
    public function __construct(private readonly SkillProgression $progression) {}

    public static function make(): self
    {
        return new self(SkillProgression::fromConfig());
    }

    /**
     * Every entry that would take this character to that level of that skill.
     *
     * One `Skill` per level, repeated, in the order they have to be queued.
     * Empty when there is nothing left to do, which is an ordinary answer.
     *
     * @return list<Skill>
     */
    public function plan(Character $character, Skill $skill, int $targetLevel): array
    {
        $targetLevel = min(max($targetLevel, 1), $this->progression->maxLevel());

        $reached = $this->reached($character);
        $planned = [];
        $order = [];

        $visit = function (Skill $skill, int $want) use (&$visit, &$planned, &$order, $reached): void {
            $have = $planned[$skill->id] ?? $reached[$skill->id] ?? 0;

            if ($have >= $want) {
                return;
            }

            // Claimed BEFORE the walk goes any deeper, so a cycle stops here
            // rather than recurring. The editor and `skills:validate` both
            // refuse cycles, which makes this a guard against a graph that
            // should not exist rather than a case anybody plans for.
            $planned[$skill->id] = $want;

            foreach ($skill->prerequisites()->with('requiredSkill')->get() as $prerequisite) {
                $required = $prerequisite->requiredSkill;

                if ($required !== null) {
                    $visit($required, (int) $prerequisite->required_level);
                }
            }

            // One entry per level, because that is what an entry is. Levels 1
            // to 3 of a skill are three presses of the same button, and this
            // is those three presses in a list.
            for ($level = $have + 1; $level <= $want; $level++) {
                $order[] = $skill;
            }
        };

        $visit($skill, $targetLevel);

        return $order;
    }

    /**
     * How far each skill will have got by the time the queue has emptied.
     *
     * The attained level, or the deepest level queued where that is further
     * along — the queue is what the plan is being added to, so planning on top
     * of "Hacking queued to III" starts at IV.
     *
     * A skill with no row at all is absent, and reads as zero: level 0 is a
     * real state (injected, untrained) and so is never having owned the book,
     * and telling them apart is `enqueue()`'s job rather than this one's.
     *
     * @return array<int, int>
     */
    private function reached(Character $character): array
    {
        $levels = $character->characterSkills()
            ->pluck('attained_level', 'skill_id')
            ->map(static fn ($level): int => (int) $level)
            ->all();

        foreach ($character->trainingQueue()->get() as $entry) {
            $id = (int) $entry->skill_id;
            $levels[$id] = max($levels[$id] ?? 0, (int) $entry->target_level);
        }

        return $levels;
    }
}
