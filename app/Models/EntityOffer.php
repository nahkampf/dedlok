<?php

declare(strict_types=1);

namespace App\Models;

use App\Standing\Service;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing an entity will hand over for loyalty.
 *
 * A SHELF IS AN ENTITY AND A RANGE OF GRADES rather than a desk. An entity
 * keeps up to five desks, one per grade, and two desks of the same grade are
 * the same shop — so `exchanges` is not referenced here at all. Most of what
 * an entity sells is available across several of its desks, which is why the
 * range is the shape rather than a row per desk.
 *
 * A row is an ITEM or a SERVICE (`App\Standing\Service`), never both: a
 * service is something the entity does rather than hands over, and lands
 * nowhere. Read by the Exchange screen, written by the admin shelf editor. `App\Standing\LoyaltyExchange` is the only thing that
 * acts on one.
 */
#[Fillable(['entity_id', 'min_grade', 'max_grade', 'item_id', 'service', 'service_amount', 'loyalty_price', 'min_standing'])]
class EntityOffer extends Model
{
    protected function casts(): array
    {
        return [
            'min_grade' => 'integer',
            'max_grade' => 'integer',
            'loyalty_price' => 'integer',
            'min_standing' => 'float',
            'service' => Service::class,
            'service_amount' => 'float',
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

    /** What the row is called on a screen, whichever kind it is. */
    public function displayName(): string
    {
        return $this->service?->label() ?? $this->item?->displayName() ?? '';
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

    /** Does a desk of this grade keep it? Inclusive at both ends. */
    public function keptAtGrade(int $grade): bool
    {
        return $grade >= $this->min_grade && $grade <= $this->max_grade;
    }

    /** How the range reads on a screen: one number when it is one desk. */
    public function gradeSpan(): string
    {
        return $this->min_grade === $this->max_grade
            ? (string) $this->min_grade
            : $this->min_grade.'-'.$this->max_grade;
    }

    /**
     * What one desk is holding.
     *
     * The grade is compared against the RANGE, so a desk sees everything whose
     * span contains it. An offer whose item has been deleted is filtered
     * rather than trusted (a service row has no item and is kept): the foreign
     * key cascades, so it should not happen, and a shop that renders a blank line because one did is worse than one
     * showing nothing.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOnShelf(Builder $query, int $entityId, int $grade): void
    {
        $query->where('entity_id', $entityId)
            ->where('min_grade', '<=', $grade)
            ->where('max_grade', '>=', $grade)
            ->where(fn (Builder $row) => $row->whereNotNull('service')->orWhereHas('item'))
            ->orderBy('loyalty_price');
    }
}
