<?php

declare(strict_types=1);

namespace App\Models;

use App\Stats\EffectDefinition;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing a character is currently carrying.
 *
 * The row names a DEFINITION and holds what is particular to this instance;
 * the arithmetic lives in `config/effects.php`. That split is what stops a
 * character's injuries from being a thousand copies of the same numbers, and
 * what lets those numbers be re-tuned without a migration.
 *
 * Expired rows are FILTERED ON READ and not trusted to have been swept. A
 * sweep is housekeeping and housekeeping can be late; a resolver that applied
 * a drug because nobody had got round to deleting it would be wrong in a way
 * nobody could reproduce.
 */
#[Fillable(['character_id', 'definition_key', 'source_type', 'source_id', 'stacks', 'expires_at', 'data'])]
class CharacterModifier extends Model
{
    protected function casts(): array
    {
        return [
            'stacks' => 'integer',
            'expires_at' => 'immutable_datetime',
            'data' => 'array',
        ];
    }

    /** @return BelongsTo<Character, $this> */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    /** What this is an instance of, or null if the definition has been lost. */
    public function definition(): ?EffectDefinition
    {
        return EffectDefinition::find($this->definition_key);
    }

    /**
     * The magnitude this instance carries, or null if it carries none.
     *
     * Only the definitions that write ':amount' read this. Everything else
     * takes its numbers from the definition, which is the ordinary case.
     */
    public function amount(): ?float
    {
        $amount = $this->data['amount'] ?? null;

        return is_numeric($amount) ? (float) $amount : null;
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Still in effect right now.
     *
     * @param  Builder<self>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->liveAt(now());
    }

    /**
     * In effect AT A MOMENT, which may be in the past.
     *
     * A row is live over an INTERVAL: from when it was applied until it
     * expires, with a null expiry meaning it never does. Being able to ask
     * about a past moment is what makes a training catch-up honest — a deposit
     * covering three hours during which a drug wore off has to credit the
     * bonus for the part of the window that had it, and not for the rest.
     *
     * `created_at` is the applied-at moment. It is not a separate column
     * because there is no case where a modifier is written down before it
     * starts applying; when there is one, that is the day this grows an
     * `applies_from`.
     *
     * @param  Builder<self>  $query
     */
    public function scopeLiveAt(Builder $query, \DateTimeInterface $at): void
    {
        $query
            ->where(function (Builder $query) use ($at): void {
                // Rows written before created_at existed are treated as having
                // always applied, rather than as never having applied.
                $query->whereNull('created_at')->orWhere('created_at', '<=', $at);
            })
            ->where(function (Builder $query) use ($at): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', $at);
            });
    }

    /** @param  Builder<self>  $query */
    public function scopeOfDefinition(Builder $query, string $key): void
    {
        $query->where('definition_key', $key);
    }

    /**
     * Everything that will stop applying inside a window.
     *
     * Used by the training deposit, which has to split its window at every
     * expiry inside it rather than applying one rate across the whole thing.
     *
     * @param  Builder<self>  $query
     */
    public function scopeExpiringBetween(Builder $query, \DateTimeInterface $from, \DateTimeInterface $to): void
    {
        $query->whereNotNull('expires_at')
            ->where('expires_at', '>', $from)
            ->where('expires_at', '<=', $to);
    }
}
