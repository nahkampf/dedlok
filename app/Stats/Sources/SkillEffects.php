<?php

declare(strict_types=1);

namespace App\Stats\Sources;

use App\Models\Character;
use App\Models\CharacterSkill;
use App\Stats\Modifier;

/**
 * What a character's skills grant them for being known.
 *
 * Not the check — that is `check.<slug>`, generated for every skill in the game
 * whether anyone has it or not. This is the other thing MECHANICS asks of a
 * skill: that HAVING it can make something else better. Thermal Management
 * lowering what a deck runs at is a fact about the person, not about the
 * hardware, and it belongs here rather than in deck code.
 *
 * ONLY INJECTED SKILLS COUNT, and only up to the level actually attained. A
 * `character_skills` row is what "injected" means, so iterating those rows is
 * the gate — no level test, no permission check, just the rows that exist.
 *
 * Grants are filtered by `level` and the key is stripped before the modifier is
 * built: Modifier::fromArray refuses unknown keys, deliberately, so the
 * filtering has to happen out here rather than being smuggled through.
 */
final readonly class SkillEffects implements ModifierSource
{
    public function __construct(private Character $character) {}

    /** @return list<Modifier> */
    public function modifiers(): array
    {
        $rows = CharacterSkill::query()
            ->where('character_id', $this->character->getKey())
            ->with('skill')
            ->get();

        $modifiers = [];

        foreach ($rows as $row) {
            $skill = $row->skill;

            if ($skill === null || ! is_array($skill->modifiers)) {
                continue;
            }

            foreach ($skill->modifiers as $grant) {
                if (! is_array($grant)) {
                    continue;
                }

                // A grant applies from its level upward. One with no level at
                // all applies from level 0 — which is what an injected but
                // untrained skill has, and is a reasonable thing for a skill
                // to be worth.
                if ($row->attained_level < (int) ($grant['level'] ?? 0)) {
                    continue;
                }

                unset($grant['level']);

                $modifiers[] = Modifier::fromArray(
                    $grant + ['source' => $skill->title]
                );
            }
        }

        return $modifiers;
    }
}
