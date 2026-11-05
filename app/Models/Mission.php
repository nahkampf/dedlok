<?php

declare(strict_types=1);

namespace App\Models;

use App\Missions\MissionAction;
use App\Missions\MissionState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One job, offered to one character by one desk.
 *
 * Written only by `App\Missions\MissionBoard` (which rolls them) and
 * `App\Missions\MissionLedger` (which moves them through their states).
 *
 * THE REWARDS ON THE ROW ARE THE ONES THAT COUNT. They were read out of
 * `config/missions.php` when the job was rolled and frozen here, so the curve
 * can be retuned tonight without changing what anybody is already owed.
 */
#[Fillable([
    'character_id', 'entity_id', 'exchange_id', 'action', 'state',
    'quantity_required', 'creds_reward', 'standing_reward', 'loyalty_reward', 'penalty',
    'offered_at', 'accepted_at', 'expires_at', 'completed_at', 'proceeds', 'heat_added',
])]
class Mission extends Model
{
    protected function casts(): array
    {
        return [
            'action' => MissionAction::class,
            'state' => MissionState::class,
            'quantity_required' => 'integer',
            'proceeds' => 'integer',
            'heat_added' => 'float',
            'creds_reward' => 'integer',
            'standing_reward' => 'float',
            'loyalty_reward' => 'integer',
            'penalty' => 'float',
            'offered_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Character, $this> */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    /** @return BelongsTo<Entity, $this> */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    /** @return BelongsTo<Exchange, $this> */
    public function exchange(): BelongsTo
    {
        return $this->belongsTo(Exchange::class);
    }

    /** @return HasMany<MissionRequirement, $this> */
    public function requirements(): HasMany
    {
        return $this->hasMany(MissionRequirement::class);
    }

    /** @return HasMany<MissionTarget, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(MissionTarget::class);
    }

    /**
     * Is everything this job asked for done?
     *
     * TWO QUESTIONS, NOT ONE, because the two kinds of job count different
     * things: a designating job counts its targets, and supply counts files
     * the runner is holding — which this cannot see, so it answers honestly
     * for the half it knows and `MissionLedger` asks the other half.
     */
    public function targetsSatisfied(): bool
    {
        return ! $this->targets()->whereNull('satisfied_at')->exists();
    }

    public function outstanding(): int
    {
        return $this->targets()->whereNull('satisfied_at')->count();
    }

    public function hasExpired(?\DateTimeInterface $now = null): bool
    {
        return $this->expires_at !== null && $this->expires_at->isBefore($now ?? now());
    }

    /** How many minutes are left, for a screen. Null when it has not been taken. */
    public function minutesLeft(): ?int
    {
        if ($this->expires_at === null) {
            return null;
        }

        return max(0, (int) round(now()->diffInMinutes($this->expires_at, absolute: false)));
    }

    /** @param  Builder<self>  $query */
    public function scopeOffered(Builder $query): void
    {
        $query->where('state', MissionState::Offered->value);
    }

    /** @param  Builder<self>  $query */
    public function scopeAccepted(Builder $query): void
    {
        $query->where('state', MissionState::Accepted->value);
    }

    /**
     * Live rows: on a board, or being worked on.
     *
     * What the one-job-per-agent cap counts, and what a board refresh has to
     * be careful not to clear.
     *
     * @param  Builder<self>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->whereIn('state', [MissionState::Offered->value, MissionState::Accepted->value]);
    }
}
