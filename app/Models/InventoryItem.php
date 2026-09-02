<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\InventoryItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a character is carrying: one row per (character, item), with a quantity.
 *
 * Which inventory a row appears in is not stored here. It comes from the item
 * definition's `container`, because it is a fact about the item and letting a
 * row disagree with its definition would make the two inventories a lie.
 *
 * WHERE a row sits is stored here, and is a different question: `parent_id`
 * names the row this one is inside, and is null for anything loose. A memory
 * card is a physical row holding data rows — the item says what kind of thing
 * it is, the row says where it ended up.
 */
#[Fillable(['character_id', 'item_id', 'parent_id', 'quantity'])]
class InventoryItem extends Model
{
    /** @use HasFactory<InventoryItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    /**
     * Keep the stacking key in step with the row.
     *
     * Derived on every save rather than assigned by callers, because a caller
     * that forgets it would silently create a second pile of the same item.
     * Unstackable rows get null, which a unique index does not police — that
     * is how two memory cards stay two rows.
     */
    protected static function booted(): void
    {
        static::saving(function (self $row): void {
            $row->stack_key = $row->stackKey();
        });
    }

    private function stackKey(): ?string
    {
        $item = $this->relationLoaded('item') ? $this->item : Item::find($this->item_id);

        if ($item === null || ! $item->stackable) {
            return null;
        }

        // Which pile this belongs to: same item, same owner, same container.
        // Two stacks of the same item in two different cards are two piles.
        return "{$this->character_id}:{$this->item_id}:".($this->parent_id ?? 0);
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** The row this one is inside, if any. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** What is inside this row. Empty for everything that is not a container. */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * How much of this container is spoken for.
     *
     * `size` is per object, so a stack of three 8-unit books occupies 24. This
     * is the first thing to read `size` at all: it has been stored and
     * unenforced since the table was created.
     */
    public function usedCapacity(): int
    {
        return $this->children
            ->sum(fn (self $child) => (int) $child->item->size * $child->quantity);
    }

    public function remainingCapacity(): int
    {
        return max(0, (int) $this->item->capacity - $this->usedCapacity());
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

    /**
     * Only rows sitting loose, not rows inside something.
     *
     * Every listing that means "the player's inventory" wants this. Without
     * it a skillbook written to a card shows up twice: once on the card and
     * once loose on the deck it was never on.
     *
     * @param  Builder<self>  $query
     */
    public function scopeLoose(Builder $query): void
    {
        $query->whereNull('parent_id');
    }

    /** @param  Builder<self>  $query */
    public function scopeInContainer(Builder $query, string $container): void
    {
        $query->whereHas('item', fn (Builder $item) => $item->where('container', $container));
    }
}
