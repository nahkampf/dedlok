<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One module, in one socket, of one deck somebody owns.
 *
 * The row `deck_slots` has been waiting for. A socket is a place and a place
 * can be pointed at; that is why sockets were rows from the start and why a
 * count could never have done the job.
 *
 * `deck_id` and `module_id` are inventory rows — particular objects. Only
 * `deck_slot_id` belongs to the definition, and it means nothing on its own:
 * "the CPU socket of a Kurogane Explorer" is shared by every Explorer in the
 * game, so the PAIR is the physical socket.
 *
 * App\Decks\ModuleFitter is the only thing that writes these.
 */
#[Fillable(['deck_id', 'deck_slot_id', 'module_id'])]
class DeckFitting extends Model
{
    /**
     * The inventory row of the deck this is installed in.
     *
     * @return BelongsTo<InventoryItem, $this>
     */
    public function deck(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'deck_id');
    }

    /**
     * The socket on the deck's DEFINITION that is occupied.
     *
     * @return BelongsTo<DeckSlot, $this>
     */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(DeckSlot::class, 'deck_slot_id');
    }

    /**
     * The inventory row of the module that went in.
     *
     * @return BelongsTo<InventoryItem, $this>
     */
    public function module(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'module_id');
    }
}
