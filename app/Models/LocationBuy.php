<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Config;

/**
 * One thing a place will buy from players.
 *
 * @property int $location_id
 * @property int $item_id
 * @property int|null $price
 */
#[Fillable(['location_id', 'item_id', 'price'])]
class LocationBuy extends Model
{
    protected $table = 'location_buys';

    protected function casts(): array
    {
        return ['price' => 'integer'];
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** What one fetches: the row's own figure, or a fraction of the item's cost. THE one place it is worked out. */
    public function unitPrice(): int
    {
        return (int) ($this->price ?? floor((int) $this->item->base_cost * (float) Config::get('city.sell.fraction', 0.4)));
    }
}
