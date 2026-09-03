<?php

declare(strict_types=1);

namespace App\Decks;

use App\Models\DeckModule;
use App\Models\Item;

/**
 * Puts an item's hardware where it belongs: sockets for a deck, a spec row
 * for a module, neither for anything else.
 *
 * One class rather than three branches in the controller, because the rule
 * that matters is the one that spans them — an item is a deck or a module or
 * neither, never two at once, so writing one side always means clearing the
 * other. Leaving a stale `deck_modules` row behind on an item that has just
 * become a deck would be invisible until something asked what socket the deck
 * wants.
 *
 * Always call it inside a transaction: it writes two tables.
 */
final class HardwareEditor
{
    /**
     * @param  array<string, mixed>  $module  deck_modules columns; ignored unless $hardware is a module
     */
    public static function apply(Item $item, ?Hardware $hardware, SlotLayout $layout, array $module = []): void
    {
        if ($hardware === null) {
            self::clear($item);
        } elseif ($hardware->isDeck()) {
            $item->deckModule()->delete();
            self::replaceSlots($item, $layout);
        } else {
            $item->deckSlots()->delete();

            DeckModule::updateOrCreate(['item_id' => $item->id], $module);
        }

        // The item was very likely read before this ran, and its relations
        // now describe the row as it was. Drop them rather than leave a
        // caller reading yesterday's sockets.
        $item->unsetRelation('deckSlots')->unsetRelation('deckModule');
    }

    public static function clear(Item $item): void
    {
        $item->deckSlots()->delete();
        $item->deckModule()->delete();
    }

    /**
     * Replace a deck's sockets wholesale.
     *
     * Delete-then-insert, for the same reason skill prerequisites are
     * replaced rather than diffed: the form submits the set it wants, and
     * nothing references a slot's id. When something does — a module fitted
     * into a particular socket — this has to become a diff, and the rows that
     * are already occupied have to survive it.
     */
    private static function replaceSlots(Item $item, SlotLayout $layout): void
    {
        $item->deckSlots()->delete();

        $rows = $layout->rows();

        if ($rows === []) {
            return;
        }

        $item->deckSlots()->createMany($rows);
    }
}
