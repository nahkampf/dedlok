<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BuysFromPlayers;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing an entity's exchange desks will buy from players.
 *
 * Scoped like its loyalty shelf (`EntityOffer`): an entity and a range of desk grades, never one desk. The limit
 * is the entity's, shared by every desk that buys the item. Written by `App\Admin\BuyListEditor`; sold to through
 * `App\Standing\DeskBuyer`.
 *
 * @property int $entity_id
 * @property int $item_id
 * @property int $min_grade
 * @property int $max_grade
 * @property int $percent
 * @property int|null $max_quantity
 * @property int $bought
 * @property CarbonImmutable|null $window_started_at
 */
#[Fillable(['entity_id', 'item_id', 'min_grade', 'max_grade', 'percent', 'max_quantity', 'bought', 'window_started_at'])]
class EntityBuy extends Model
{
    use BuysFromPlayers;

    protected function casts(): array
    {
        return $this->buyCasts() + ['min_grade' => 'integer', 'max_grade' => 'integer'];
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @param  Builder<self>  $query */
    public function scopeAtGrade(Builder $query, int $grade): void
    {
        $query->where('min_grade', '<=', $grade)->where('max_grade', '>=', $grade);
    }
}
