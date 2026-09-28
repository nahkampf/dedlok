<?php

declare(strict_types=1);

namespace App\Decks;

use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Stats\Resolver;

/**
 * How much a character's data takes up, against how much their deck holds.
 *
 * THE DATA INVENTORY LIVES ON THE EQUIPPED DECK. Everything in it counts —
 * programs at their `storage_size`, an extracted file at its own QB, every
 * other data item (imprints included) at `items.size` — once per copy, and
 * whether it is idle, running or listed for sale, because all of those are
 * still somebody's. Only a row inside a container (a memory shard) does not:
 * that is what a shard is for.
 *
 * Capacity is the equipped deck's RESOLVED `deck_storage`, through
 * `DeckTotals::forCharacter()` like every other measure — so the onboard
 * storage every deck has, a storage module and the Cyberdecks skill all
 * arrive by the ordinary road. No deck, no storage.
 *
 * Two things read it. Every door that ADDS data asks `fits()` and refuses
 * with its own words; and `DeckRequirement::Storage` makes an over-full deck
 * unready, which gates jacking in and starting programs. Nothing is ever
 * deleted for being over: swapping to a smaller deck is allowed, and the
 * player makes room.
 */
final class Storage
{
    /** QB one copy of this item takes up. Physical things take none. */
    public static function sizeOf(Item $item): int
    {
        if ($item->container !== Item::CONTAINER_DATA) {
            return 0;
        }

        return (int) ($item->program?->storage_size ?? $item->size);
    }

    /** QB this row takes up — a file carries its own size, not its item's. */
    public static function sizeOfRow(InventoryItem $row): int
    {
        if ($row->extracted_file !== null) {
            return (int) ($row->extracted_file['size_qb'] ?? 0);
        }

        return $row->item === null ? 0 : self::sizeOf($row->item) * (int) $row->quantity;
    }

    public static function used(Character $character): int
    {
        return (int) $character->inventory()
            ->whereNull('parent_id')
            ->whereHas('item', fn ($query) => $query->where('container', Item::CONTAINER_DATA))
            ->with('item.program')
            ->get()
            ->sum(fn (InventoryItem $row): int => self::sizeOfRow($row));
    }

    public static function capacity(Character $character): int
    {
        $deck = $character->equippedDeck;

        return $deck === null ? 0 : DeckTotals::forCharacter($character, $deck, app(Resolver::class))->storage;
    }

    /** Would this many more QB still fit? Zero always fits — physical goods ask this too. */
    public static function fits(Character $character, int $qb): bool
    {
        return $qb <= 0 || self::used($character) + $qb <= self::capacity($character);
    }

    public static function free(Character $character): int
    {
        return max(0, self::capacity($character) - self::used($character));
    }
}
