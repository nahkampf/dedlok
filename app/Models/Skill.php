<?php

declare(strict_types=1);

namespace App\Models;

use App\Skills\SkillProgression;
use Database\Factories\SkillFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A skill definition.
 *
 * Definitions live in the database rather than config — see the migration for
 * why, and MECHANICS.md for what that costs.
 */
#[Fillable([
    'slug', 'title', 'description', 'category', 'rank',
    'primary_attribute', 'secondary_attribute', 'base_cost', 'on_market',
])]
class Skill extends Model
{
    /** @use HasFactory<SkillFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'rank' => 'integer',
            'base_cost' => 'integer',
            'on_market' => 'boolean',
        ];
    }

    /** What this skill requires before it can be learned. */
    public function prerequisites(): HasMany
    {
        return $this->hasMany(SkillPrerequisite::class);
    }

    /** Skills that require THIS one. The other side of the graph. */
    public function unlocks(): HasMany
    {
        return $this->hasMany(SkillPrerequisite::class, 'required_skill_id');
    }

    public function characters(): BelongsToMany
    {
        return $this->belongsToMany(Character::class, 'character_skills')
            ->using(CharacterSkill::class)
            ->withPivot(['skill_points', 'attained_level'])
            ->withTimestamps();
    }

    /** @param  Builder<self>  $query */
    public function scopeOnMarket(Builder $query): void
    {
        $query->where('on_market', true);
    }

    /** @param  Builder<self>  $query */
    public function scopeInCategory(Builder $query, string $category): void
    {
        $query->where('category', $category);
    }

    /** Cumulative points needed to reach a level in THIS skill, rank applied. */
    public function pointsForLevel(int $level): int
    {
        return SkillProgression::fromConfig()->threshold($level, $this->rank);
    }

    /** The most points this skill can hold. */
    public function pointsCap(): int
    {
        return SkillProgression::fromConfig()->cap($this->rank);
    }

    /** @return array<int, int> */
    public function thresholds(): array
    {
        return SkillProgression::fromConfig()->thresholds($this->rank);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
