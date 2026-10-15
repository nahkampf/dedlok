<?php

declare(strict_types=1);

namespace App\Inventory;

use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;

/**
 * How a catalogue item becomes something somebody has.
 *
 * ONE RULE, THREE DOORS. An archetype's loadout grants items at creation, a
 * raid takes them off a machine, and the market sells them — and all three
 * mean the same thing by "now they have it". This was written out twice before
 * the market made it three times, which is the point at which a rule stops
 * being a rule and becomes a coincidence.
 *
 * ## Stackable merges, unstackable multiplies
 *
 * Two piles of one stackable thing in one place are one pile, however each
 * pile arrived — the unique `stack_key` says so, and code that only works
 * because the table is empty is code waiting for the day it is not. Something
 * unstackable becomes one row per copy: two cyberdecks are two objects, which
 * is exactly what unstackable means.
 *
 * ## Always loose
 *
 * Never inside a container. Putting something into one would mean deciding
 * WHICH, and that is `App\Inventory\ItemPlacer`'s question and the player's
 * choice. Which inventory it lands in is not a choice at all — the item's own
 * `container` says.
 */
final class ItemGranter
{
    /**
     * Put $quantity of $item into this character's hands.
     *
     * Returns how many were granted, which is zero for a quantity nobody asked
     * for — an ordinary answer rather than an error.
     */
    public static function grant(Character $character, Item $item, int $quantity = 1): int
    {
        if ($quantity < 1) {
            return 0;
        }

        if (! $item->stackable) {
            for ($copy = 0; $copy < $quantity; $copy++) {
                $character->inventory()->create([
                    'item_id' => $item->id,
                    'parent_id' => null,
                    'quantity' => 1,
                ]);
            }

            return $quantity;
        }

        $existing = InventoryItem::query()
            ->where('character_id', $character->id)
            ->where('item_id', $item->id)
            ->whereNull('parent_id')
            // A copy that is running (or belted) is a row of its own; adding to it would hand a running Script a second life.
            ->whereNull('running_on_deck_id')
            ->whereNull('belt_slot')
            // A pile on the player market is spoken for: merging into it would hand the copy to whoever buys the listing.
            ->whereDoesntHave('offer')
            ->first();

        if ($existing !== null) {
            $existing->quantity += $quantity;
            $existing->save();

            return $quantity;
        }

        $character->inventory()->create([
            'item_id' => $item->id,
            'parent_id' => null,
            'quantity' => $quantity,
        ]);

        return $quantity;
    }
}
