<?php

namespace Tests\Concerns;

use App\Decks\DeckEquipper;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;

/**
 * Give a character a deck with room for whatever the test hands them.
 *
 * The data inventory lives on the equipped deck (App\Decks\Storage), so a
 * character with no deck can hold no data at all. A test about buying,
 * extracting or unpacking data is not a test about storage, and this keeps
 * storage out of its way: a bare deck, equipped, with a huge onboard figure.
 * It does not RUN — nothing here needs it to.
 */
trait HasRoomForData
{
    protected function giveRoomForData(Character $character): void
    {
        config(['decks.onboard_storage' => 1_000_000]);

        $deck = InventoryItem::create([
            'character_id' => $character->id,
            'item_id' => Item::factory()->cyberdeck()->create()->id,
            'quantity' => 1,
        ]);

        app(DeckEquipper::class)->equip($character, $deck);
        $character->refresh();
    }
}
