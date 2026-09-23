<?php

declare(strict_types=1);

namespace App\Models;

use App\Standing\LedgerKind;
use App\Standing\StandingReason;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change to one of an entity's two numbers, and it never changes again.
 *
 * Immutable and without `timestamps()`, the call `market_purchases` makes:
 * `happened_at` is the moment, and a row that is never updated has no use for
 * a second date. What is stored is WHAT LANDED, so summing `delta` for a kind
 * reproduces the books exactly.
 */
#[Fillable(['character_id', 'entity_id', 'kind', 'delta', 'reason', 'happened_at'])]
class EntityLedgerEntry extends Model
{
    protected $table = 'entity_ledger';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'kind' => LedgerKind::class,
            'reason' => StandingReason::class,
            'delta' => 'float',
            'happened_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Entity, $this> */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    /** @param  Builder<self>  $query */
    public function scopeOfKind(Builder $query, LedgerKind $kind): void
    {
        $query->where('kind', $kind);
    }
}
