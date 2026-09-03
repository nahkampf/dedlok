<?php

declare(strict_types=1);

namespace App\Models;

use App\Decks\SlotKind;
use App\Decks\SlotType;
use App\Decks\Variant;
use Database\Factories\DeckSlotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One socket on one cyberdeck definition.
 *
 * A row per socket rather than a count per kind, because a socket is a place:
 * a module fitted into a deck will point at the one it went into, and a count
 * cannot be pointed at. App\Decks\SlotLayout is the same set seen as counts,
 * which is what the editor and every spec line actually want.
 *
 * These belong to the item, not to a character. What a player has fitted
 * where is a different table that does not exist yet.
 */
#[Fillable(['item_id', 'kind', 'variant', 'position'])]
class DeckSlot extends Model
{
    /** @use HasFactory<DeckSlotFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'kind' => SlotKind::class,
            'variant' => Variant::class,
            'position' => 'integer',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** The (kind, variant) pair this row is, which is what decides fit. */
    public function type(): SlotType
    {
        return SlotType::of($this->kind, $this->variant);
    }

    /**
     * May $module be fitted here?
     *
     * The item's `hardware` says what kind of module it is and `deck_modules`
     * says which socket it wants; a module item missing its spec row fits
     * nowhere, which is the safe way round.
     */
    public function accepts(Item $module): bool
    {
        if ($module->hardware === null) {
            return false;
        }

        return $this->type()->accepts($module->hardware, $module->deckModule?->variant);
    }

    /** @param  Builder<self>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('position');
    }
}
