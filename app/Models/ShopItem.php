<?php

declare(strict_types=1);

namespace App\Models;

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
 */
#[Fillable(['location_id', 'item_id', 'price'])]
class ShopItem extends Model
{
    protected function casts(): array
    {
        return ['price' => 'integer'];
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** What one costs: the row's own figure, or the item's. THE one place a price is worked out. */
    public function unitPrice(): int
    {
        return (int) ($this->price ?? $this->item->base_cost);
    }
}
