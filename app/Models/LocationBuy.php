<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BuysFromPlayers;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing a place will buy from players.
 *
 * @property int $location_id
 * @property int $item_id
 * @property int $percent
 * @property int|null $max_quantity
 * @property int $bought
 * @property CarbonImmutable|null $window_started_at
 */
#[Fillable(['location_id', 'item_id', 'percent', 'max_quantity', 'bought', 'window_started_at'])]
class LocationBuy extends Model
{
    use BuysFromPlayers;

    protected $table = 'location_buys';

    protected function casts(): array
    {
        return $this->buyCasts();
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
