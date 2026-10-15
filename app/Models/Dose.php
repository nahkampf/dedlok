<?php

declare(strict_types=1);

namespace App\Models;

use App\Stats\Modifier;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something somebody took, while it is still in them.
 *
 * The row `App\Stats\Sources\ActiveDoses` reads. It points at the ITEM rather
 * than copying its modifiers, so retuning a drug retunes what is already in
 * everybody — the same call every other source makes about the definition it
 * reads from.
 *
 * WHAT IT DOES COPY IS THE POTENCY, because tolerance is a property of the
 * dose: what you swallowed is what you swallowed, and a band that climbs an
 * hour later does not reach back into your blood.
 */
#[Fillable(['character_id', 'item_id', 'potency', 'started_at', 'expires_at', 'turns_left'])]
class Dose extends Model
{
    protected $table = 'character_doses';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'potency' => 'float',
            'started_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** Still in effect. Expiry is filtered on read, never swept. */
    public function scopeInEffect(Builder $query, ?\DateTimeInterface $at = null): void
    {
        $query->where('expires_at', '>', $at ?? now())->where(static fn ($turns) => $turns->whereNull('turns_left')->orWhere('turns_left', '>', 0));
    }

    /**
     * What this dose does to the person right now, one line per effect: the amounts as the resolver applies them
     * (a gain is worth its potency, a cost is not). For the character sheet.
     *
     * @return list<array{text: string, negative: bool}>
     */
    public function effectLines(): array
    {
        $lines = [];

        foreach ((array) $this->item?->modifiers as $shape) {
            if (! is_array($shape)) {
                continue;
            }

            $amount = $shape['amount'] ?? null;

            if (is_numeric($amount) && $amount > 0 && (float) $this->potency < 1.0) {
                $shape['amount'] = round((float) $amount * (float) $this->potency, 2);
            }

            try {
                $modifier = Modifier::fromArray($shape);
            } catch (\Throwable) {
                continue;
            }

            $lines[] = ['text' => $modifier->effectText(), 'negative' => (float) ($shape['amount'] ?? 0) < 0];
        }

        return $lines;
    }
}
