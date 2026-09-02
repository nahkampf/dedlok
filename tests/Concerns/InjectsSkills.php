<?php

namespace Tests\Concerns;

use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\Skill;

/**
 * Put a skill on a character's sheet, the way injecting a skillbook does.
 *
 * Since the skillbook gate went in, a character_skills row is the only thing
 * that makes a skill trainable, and App\Inventory\SkillInjector is the only
 * thing that creates one. Tests *about* training want that state without
 * buying a book first, so they write the row directly; tests about the gate
 * go through the injector, which is what InventoryTest does.
 */
trait InjectsSkills
{
    protected function inject(Character $character, Skill $skill, int $points = 0, int $level = 0): CharacterSkill
    {
        return CharacterSkill::create([
            'character_id' => $character->id,
            'skill_id' => $skill->id,
            'skill_points' => $points,
            'attained_level' => $level,
        ]);
    }
}
