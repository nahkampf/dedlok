<?php

declare(strict_types=1);

namespace App\Models;

use App\Skills\SkillProgression;
use App\Stats\SkillStats;
use Database\Factories\SkillFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A skill definition.
 *
 * Definitions live in the database rather than config — see the migration for
 * why, and MECHANICS.md for what that costs.
 */
#[Fillable([
    'slug', 'title', 'description', 'category', 'rank',
    'primary_attribute', 'secondary_attribute', 'base_cost', 'on_market',
    'modifiers',
])]
class Skill extends Model
{
    /** @use HasFactory<SkillFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            // What having the skill grants. Each entry carries a `level`;
            // App\Stats\Sources\SkillEffects is the only reader.
            'modifiers' => 'array',
            'rank' => 'integer',
            'base_cost' => 'integer',
            'on_market' => 'boolean',
        ];
    }

    /**
     * The one book that teaches this skill.
     *
     * A relation rather than a lookup because it is the shape of the
     * invariant: exactly one book per skill, which is what lets a book have
     * no name of its own. The use_op filter is what makes it "the book"
     * rather than "an item that happens to point here" — use_skill_id is
     * only ever set alongside inject_skill, and the schema says so.
     *
     * @return HasOne<Item, $this>
     */
    public function imprint(): HasOne
    {
        return $this->hasOne(Item::class, 'use_skill_id')
            ->where('use_op', Item::USE_INJECT_SKILL);
    }

    /**
     * What this skill requires before it can be learned.
     *
     * @return HasMany<SkillPrerequisite, $this>
     */
    public function prerequisites(): HasMany
    {
        return $this->hasMany(SkillPrerequisite::class);
    }

    /**
     * Skills that require THIS one. The other side of the graph.
     *
     * @return HasMany<SkillPrerequisite, $this>
     */
    public function unlocks(): HasMany
    {
        return $this->hasMany(SkillPrerequisite::class, 'required_skill_id');
    }

    /** @return BelongsToMany<Character, $this, CharacterSkill, 'pivot'> */
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

    /**
     * Every skill generates two stats — `skill.<slug>` and `check.<slug>` —
     * and that list is cached, because check resolution reads it on nearly
     * every request. Renaming, adding or deleting a skill has to drop it, and
     * doing that here rather than in the admin controller means a seeder, a
     * tinker session and the editor all stay in step.
     */
    protected static function booted(): void
    {
        static::saved(static fn () => SkillStats::forget());
        static::deleted(static fn () => SkillStats::forget());
    }
}
