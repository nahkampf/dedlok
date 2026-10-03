<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing a shop sells.
 *
 * @property int $id
 * @property int $location_id
 * @property int $item_id
 * @property int|null $price
 * @property int|null $max_stock
 * @property int $stock
 * @property int $restock_amount
 * @property int|null $restock_hours
 * @property CarbonImmutable|null $restocked_at
 */
#[Fillable(['location_id', 'item_id', 'price', 'max_stock', 'stock', 'restock_amount', 'restock_hours', 'restocked_at'])]
class ShopItem extends Model
{
    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'max_stock' => 'integer',
            'stock' => 'integer',
            'restock_amount' => 'integer',
            'restock_hours' => 'integer',
            'restocked_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** No maximum means no stock to run out of. */
    public function isUnlimited(): bool
    {
        return $this->max_stock === null;
    }

    /**
     * Bring the shelf up to date: each whole interval since the last boundary adds
     * `restock_amount`, up to `max_stock`. The boundary advances by whole
     * intervals even when the shelf is full, so a full shelf banks nothing.
     * Does not save. Call it under a lock.
     */
    public function restock(CarbonInterface $now): void
    {
        if ($this->isUnlimited() || ! $this->restock_hours || $this->restock_amount < 1) {
            return;
        }

        $since = $this->restocked_at ?? CarbonImmutable::instance($now);
        $intervals = intdiv(max(0, (int) floor($since->diffInSeconds($now, false))), $this->restock_hours * 3600);

        if ($intervals < 1) {
            return;
        }

        $this->stock = min((int) $this->max_stock, $this->stock + $intervals * $this->restock_amount);
        $this->restocked_at = $since->addHours($intervals * $this->restock_hours);
    }

    /** What one costs: the row's own figure, or the item's. THE one place a price is worked out. */
    public function unitPrice(): int
    {
        return (int) ($this->price ?? $this->item->base_cost);
    }
}
