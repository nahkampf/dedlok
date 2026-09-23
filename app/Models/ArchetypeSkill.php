<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One skill an archetype starts you with, and how far along it already is.
 *
 * A grant, not progress: what a character actually knows is a
 * `character_skills` row, written once at creation by
 * App\Inventory\SkillInjector. This row is the instruction to write that one,
 * and editing it later changes what the NEXT character gets — never what an
 * existing one has.
 *
 * Level 0 is the default and a real state: injected and untrained, exactly
 * what spending an imprint buys. Anything above it is the archetype saying
 * the training already happened.
 */
#[Fillable(['archetype_id', 'skill_id', 'level'])]
class ArchetypeSkill extends Model
{
    protected function casts(): array
    {
        return ['level' => 'integer'];
    }

    /** @return BelongsTo<Archetype, $this> */
    public function archetype(): BelongsTo
    {
        return $this->belongsTo(Archetype::class);
    }

    /** @return BelongsTo<Skill, $this> */
    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    protected static function booted(): void
    {
        // The memoised roster hands out archetype instances the loadout
        // screens then load these rows onto. Drop it, or an edit leaves a
        // cached archetype describing the loadout it used to have.
        static::saved(Archetype::forgetRoster(...));
        static::deleted(Archetype::forgetRoster(...));
    }
}
