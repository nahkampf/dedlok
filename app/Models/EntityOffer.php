<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing an entity will hand over for loyalty.
 *
 * A SHELF IS AN (ENTITY, GRADE) PAIR rather than a desk. An entity keeps up to
 * five desks, one per grade, and two desks of the same grade are the same shop
 * — so `exchanges` is not referenced here at all.
 *
 * Read by the Exchange screen, written by nobody yet: the shelf is authored,
 * the way items are. `App\Standing\LoyaltyExchange` is the only thing that
 * acts on one.
 */
#[Fillable(['entity_id', 'grade', 'item_id', 'loyalty_price', 'min_standing'])]
class EntityOffer extends Model
{
    protected function casts(): array
    {
        return [
            'grade' => 'integer',
            'loyalty_price' => 'integer',
            'min_standing' => 'float',
        ];
    }

    /** @return BelongsTo<Entity, $this> */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * Will they hand THIS row to somebody standing there?
     *
     * The desk's own gate is a separate question and is asked first — see
     * `Exchange::willDealWith()`. This is the second one, and it is almost
     * always satisfied: `min_standing` defaults to 0.
     */
    public function willPartWithIt(float $standing): bool
    {
        return $standing >= $this->min_standing;
    }

    /**
     * What one desk is holding.
     *
     * An offer whose item has been deleted is filtered rather than trusted:
     * the foreign key cascades, so it should not happen, and a shop that
     * renders a blank line because one did is worse than one showing nothing.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOnShelf(Builder $query, int $entityId, int $grade): void
    {
        $query->where('entity_id', $entityId)
            ->where('grade', $grade)
            ->whereHas('item')
            ->orderBy('loyalty_price');
    }
}
