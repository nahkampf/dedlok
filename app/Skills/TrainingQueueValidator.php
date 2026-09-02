<?php

declare(strict_types=1);

namespace App\Skills;

use App\Models\Character;

/**
 * Checks a training queue by simulating it forward from the character's
 * current skills.
 *
 * None of these rules are visible in a single row, which is the point. Whether
 * "Codebreaking I" is valid at position 6 depends entirely on what positions
 * 1 to 5 will have completed by the time it starts.
 */
final class TrainingQueueValidator
{
    /**
     * @return list<string> human-readable problems, empty when the queue is sound
     */
    public function problemsFor(Character $character): array
    {
        $entries = $character->trainingQueue()->with('skill')->get();

        if ($entries->isEmpty()) {
            return [];
        }

        $problems = $this->positionProblems($entries->pluck('position')->all());

        // Simulated state: what the character will know as the queue advances.
        $levels = $character->characterSkills()
            ->pluck('attained_level', 'skill_id')
            ->map(fn ($level): int => (int) $level)
            ->all();

        // A character_skills row exists only because a skillbook was injected,
        // so the keys of that map are exactly the skills this character is
        // allowed to train. Level 0 is a real state here — injected, untrained
        // — which is why this is a key check and not a level check.
        $injected = array_keys($levels);

        foreach ($entries as $entry) {
            $skill = $entry->skill;

            if ($skill === null) {
                $problems[] = "position {$entry->position} refers to a skill that no longer exists";

                continue;
            }

            if (! in_array($skill->id, $injected, true)) {
                $problems[] = "position {$entry->position} trains [{$skill->slug}] but it has never been ".
                    'injected — the skillbook has to be used first';

                continue;
            }

            $current = $levels[$skill->id] ?? 0;

            // Each level is queued separately, so a target must be exactly the
            // next one up. Jumping means the levels in between never train.
            if ($entry->target_level !== $current + 1) {
                $problems[] = $current >= $entry->target_level
                    ? "position {$entry->position} queues [{$skill->slug}] level {$entry->target_level}, ".
                      'already reached by that point'
                    : "position {$entry->position} queues [{$skill->slug}] level {$entry->target_level} ".
                      'but level '.($current + 1).' is not queued before it';
            }

            // Prerequisites are checked at the point the entry STARTS, not at
            // the point it was added — that is what lets the example queue
            // Codebreaking behind the Hacking and Cryptography that unlock it.
            foreach ($skill->prerequisites()->with('requiredSkill')->get() as $prerequisite) {
                $held = $levels[$prerequisite->required_skill_id] ?? 0;

                if ($held < $prerequisite->required_level) {
                    $required = $prerequisite->requiredSkill?->slug ?? "#{$prerequisite->required_skill_id}";

                    $problems[] = "position {$entry->position} trains [{$skill->slug}] but needs ".
                        "[{$required}] at level {$prerequisite->required_level}, and only {$held} is ".
                        'reached by then';
                }
            }

            $levels[$skill->id] = max($current, $entry->target_level);
        }

        return $problems;
    }

    /**
     * @param  list<int>  $positions
     * @return list<string>
     */
    private function positionProblems(array $positions): array
    {
        sort($positions);

        return $positions === range(1, count($positions))
            ? []
            : ['queue positions ['.implode(', ', $positions).'] are not a gapless 1..n'];
    }
}
