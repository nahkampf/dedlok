<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\InventoryItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a character is carrying: one row per (character, item), with a quantity.
 *
 * Which inventory a row appears in is not stored here. It comes from the item
 * definition's `container`, because it is a fact about the item and letting a
 * row disagree with its definition would make the two inventories a lie.
 */
#[Fillable(['character_id', 'item_id', 'quantity'])]
class InventoryItem extends Model
{
    /** @use HasFactory<InventoryItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }


    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }


    /**
     * Take one away, and delete the row rather than leave a zero.
     *
     * A quantity of 0 is not a thing a player owns, and the database refuses
     * it outright.
     */
    public function consumeOne(): void
    {
        if ($this->quantity <= 1) {
            $this->delete();

            return;
        }

        $this->decrement('quantity');
    }


    /** @param  Builder<self>  $query */
    public function scopeInContainer(Builder $query, string $container): void
    {
        $query->whereHas('item', fn (Builder $item) => $item->where('container', $container));
    }
}
