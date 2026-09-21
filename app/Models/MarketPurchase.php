<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\MarketPurchaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One purchase, written once and never touched again.
 *
 * This is the demand signal. App\Market\MarketSeeder sums a window of it per
 * item and stocks more of whatever is selling, so a row here is not only a
 * receipt — it is the input to what the market carries next.
 *
 * `$timestamps = false` because a row that is never updated has no use for an
 * `updated_at`, and `purchased_at` already says what the moment means.
 *
 * `character_id` may be null: a deleted character's purchases stay, because
 * the demand they represent was real. Nothing reads the buyer back yet.
 */
#[Fillable(['character_id', 'item_id', 'quantity', 'unit_price', 'purchased_at'])]
class MarketPurchase extends Model
{
    /** @use HasFactory<MarketPurchaseFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'purchased_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return BelongsTo<Character, $this> */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    /** @param  Builder<self>  $query */
    public function scopeSince(Builder $query, CarbonInterface $moment): void
    {
        $query->where('purchased_at', '>=', $moment);
    }
}
