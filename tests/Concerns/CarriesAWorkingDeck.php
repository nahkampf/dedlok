<?php

namespace Tests\Concerns;

use App\Decks\DeckEquipper;
use App\Decks\Hardware;
use App\Decks\ModuleFitter;
use App\Decks\Variant;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;

/**
 * Give a character a deck that actually runs.
 *
 * Standing in cyberspace WITHOUT one is a state the game does not allow: the
 * deck lock stops a player taking their machine apart from the inside, and
 * App\Cyberspace\ReadinessWatch throws out anybody whose deck has stopped
 * working. A fixture that wrote `grid_x` and `grid_y` onto a character with no
 * deck used to be a harmless shortcut and is now a character the next request
 * ejects — so every test that needs somebody inside builds the machine that
 * keeps them there.
 *
 * Compute, memory, link and power, because those are what isReady() asks for,
 * and a supply that dwarfs the draw so the budget is never the thing under
 * test.
 */
trait CarriesAWorkingDeck
{
    protected function equipReadyDeck(Character $character): InventoryItem
    {
        $deckItem = Item::factory()->cyberdeck([
            'cpu_socket_k' => 1, 'ram_opto' => 1, 'nic' => 1, 'psu' => 1,
        ])->create();

        $deck = $this->ownedBy($character, $deckItem);
        $fitter = app(ModuleFitter::class);

        $parts = [
            ['cpu', Hardware::Cpu, Variant::SocketK, 100],
            ['ram', Hardware::Ram, Variant::Opto, 100],
            ['nic', Hardware::Nic, null, 50],
            ['psu', Hardware::Psu, null, 2000],
        ];

        foreach ($parts as [$kind, $hardware, $variant, $measure]) {
            $fitter->fit(
                $character,
                $deck,
                $deckItem->deckSlots()->where('kind', $kind)->firstOrFail(),
                $this->ownedBy($character, Item::factory()->module($hardware, $variant, measure: $measure, draw: 10)->create()),
            );
        }

        app(DeckEquipper::class)->equip($character, $deck);
        $character->refresh();

        return $deck;
    }

    protected function ownedBy(Character $character, Item $item): InventoryItem
    {
        return InventoryItem::create([
            'character_id' => $character->id,
            'item_id' => $item->id,
            'quantity' => 1,
        ]);
    }
}
