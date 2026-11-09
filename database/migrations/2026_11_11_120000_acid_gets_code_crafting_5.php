<?php

use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\Skill;
use App\Skills\SkillProgression;
use Illuminate\Database\Migrations\Migration;

/**
 * Code Crafting at level 5 for ACiD: the points for the level exactly (the cap for the skill's rank), the row made if the
 * character never had one. Never lowers anything. A game without a character called ACiD, or without the skill, is left
 * alone; no schema changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $character = Character::query()->where('handle', 'ACiD')->first();
        $skill = Skill::query()->where('slug', 'code_crafting')->first();

        if ($character === null || $skill === null) {
            return;
        }

        $progression = SkillProgression::fromConfig();
        $row = CharacterSkill::query()->firstOrNew(['character_id' => $character->getKey(), 'skill_id' => $skill->getKey()]);

        $row->forceFill([
            'skill_points' => max((int) $row->skill_points, $progression->cap((int) $skill->rank)),
            'attained_level' => max((int) $row->attained_level, $progression->maxLevel()),
        ])->save();
    }

    public function down(): void
    {
        // The points may have been added to since; there is nothing safe to take back.
    }
};
