<?php

declare(strict_types=1);

namespace App\Models;

use App\Skills\SkillProgression;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * What a character knows, and how far along they are.
 *
 * skill_points accrues and is never spent. attained_level is denormalised from
 * it deliberately: the minute deposit is the only writer and updates both in
 * the same operation, which is what keeps them honest. Anything else writing
 * skill_points directly is a bug — go through the deposit path, or the level
 * silently goes stale.
 *
 * This row is PROGRESS only. What a character intends to train, and in what
 * order, lives in training_queue — a queue entry is a (skill, level) pair, and
 * one skill can appear in several of them.
 *
 * App\Skills\TrainingDeposit is that single writer; everything here reads.
 */
#[Fillable(['character_id', 'skill_id', 'skill_points', 'attained_level'])]
class CharacterSkill extends Pivot
{
    protected $table = 'character_skills';

    public $incrementing = true;

    protected function casts(): array
    {
        return [
            'skill_points' => 'integer',
            'attained_level' => 'integer',
        ];
    }

    /** @return BelongsTo<Character, $this> */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    /** @return BelongsTo<Skill, $this> */
    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    /**
     * The level the stored points actually imply.
     *
     * Recomputed rather than read, so it can be compared against the stored
     * attained_level. skills:validate uses exactly this to detect drift.
     */
    public function derivedLevel(): int
    {
        return SkillProgression::fromConfig()->levelFor($this->skill_points, $this->skill->rank);
    }

    public function isAtCap(): bool
    {
        return $this->skill_points >= $this->skill->pointsCap();
    }

    public function pointsToNextLevel(): ?int
    {
        return SkillProgression::fromConfig()
            ->pointsToNextLevel($this->skill_points, $this->skill->rank);
    }
}
