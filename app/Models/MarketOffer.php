<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One player selling one row of their stash.
 *
 * THE ROW IS THE GOODS, which is what separates this from `market_stock`. A
 * shelf sells catalogue entries and conjures a copy on payment; an offer hands
 * over the particular object it points at, with whatever is written on it — a
 * scan file's facts, an extracted file's numbers, a deck's fitted modules.
 *
 * Written only by `App\Market\OfferBook`.
 */
#[Fillable(['seller_character_id', 'inventory_item_id', 'unit_price'])]
class MarketOffer extends Model
{
    protected function casts(): array
    {
        return [
            'unit_price' => 'integer',
        ];
    }

    /** @return BelongsTo<Character, $this> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'seller_character_id');
    }

    /** @return BelongsTo<InventoryItem, $this> */
    public function row(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    /**
     * How many are still on offer.
     *
     * The ROW's quantity, never a column here. Buying part of a stack leaves
     * the rest listed, so what is left and what is for sale are the same
     * number — and two copies of it would be one copy too many.
     */
    public function available(): int
    {
        return (int) ($this->row?->quantity ?? 0);
    }

    public function total(int $quantity): int
    {
        return $this->unit_price * $quantity;
    }

    /**
     * Everything on offer, cheapest first.
     *
     * A row whose goods have gone is filtered rather than trusted: the foreign
     * key cascades, so it should not happen, and a shop that renders a blank
     * line because one did is worse than one that quietly shows nothing.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOnOffer(Builder $query): void
    {
        $query->whereHas('row')->orderBy('unit_price');
    }
}
