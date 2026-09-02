<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "This skill cannot be learned until you have that one at level N."
 *
 * The set of these forms a directed graph that MUST stay acyclic — a cycle is
 * a skill nobody can ever learn, and nothing about it looks wrong from any
 * single row. skills:validate is what catches it.
 */
#[Fillable(['skill_id', 'required_skill_id', 'required_level'])]
class SkillPrerequisite extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['required_level' => 'integer'];
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    public function requiredSkill(): BelongsTo
    {
        return $this->belongsTo(Skill::class, 'required_skill_id');
    }
}
