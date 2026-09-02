<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step in a character's training queue: "take this skill to that level".
 *
 * A queue entry is a (skill, target level) PAIR, not a skill. The same skill
 * appears once per level being trained, and entries from different skills
 * interleave freely:
 *
 *     1. Hacking I      4. Cryptography IV
 *     2. Hacking II     5. Cryptography V
 *     3. Hacking III    6. Codebreaking I
 *
 * Progress lives on character_skills; this is only intent and ordering.
 */
#[Fillable([
    'character_id', 'skill_id', 'target_level', 'position',
    'last_deposited_at', 'sp_per_minute',
])]
class TrainingQueueEntry extends Model
{
    protected $table = 'training_queue';

    /**
     * The training clock is stored to the microsecond, and Eloquent writes
     * dates with this format rather than with the cast's. Without it the
     * fractional second is truncated on save and the deposit leaks a fraction
     * of a point every run — see App\Skills\TrainingDeposit.
     */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'target_level' => 'integer',
            'position' => 'integer',
            'last_deposited_at' => 'datetime',
            'sp_per_minute' => 'decimal:2',
        ];
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    /** The entry currently training. Position 1, by definition. */
    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('position', 1);
    }

    /** @param  Builder<self>  $query */
    public function scopeInOrder(Builder $query): void
    {
        $query->orderBy('position');
    }

    public function isActive(): bool
    {
        return $this->position === 1;
    }

    /** The character's progress row for this entry's skill, if they have one. */
    public function progress(): ?CharacterSkill
    {
        return CharacterSkill::query()
            ->where('character_id', $this->character_id)
            ->where('skill_id', $this->skill_id)
            ->first();
    }

    /** Has the character already reached what this entry aims at? */
    public function isSatisfied(): bool
    {
        return ($this->progress()?->attained_level ?? 0) >= $this->target_level;
    }
}
