<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What the market is holding: one row per item, with a shelf count.
 *
 * No price. What a buyer pays comes from the item's `base_cost`, so a shelf
 * row cannot drift out of step with the catalogue it is selling from.
 *
 * A missing row means a stock of zero and is entirely normal — rows are
 * written by the first restock that actually delivers something, and an item
 * whose rarity roll keeps failing may not have one for days. Anything reading
 * stock has to treat absent and empty the same way.
 */
#[Fillable(['item_id', 'quantity', 'last_seeded_at'])]
class MarketStock extends Model
{
    /** Singular: it is a mass noun, and pluralising it gives `market_stocks`. */
    protected $table = 'market_stock';

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'last_seeded_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * Rows with something actually on the shelf.
     *
     * @param  Builder<self>  $query
     */
    public function scopeAvailable(Builder $query): void
    {
        $query->where('quantity', '>', 0);
    }
}
