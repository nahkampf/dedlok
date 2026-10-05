<?php

declare(strict_types=1);

namespace App\Combat;

use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\WornSlot;
use Illuminate\Support\Facades\DB;

/**
 * Puts armour on and takes it off. The only writer of `worn_armour`.
 *
 * A piece covers a SET of slots (`armour.slots`), and wearing it takes every one of them:
 * a row each, all pointing at the one inventory row. WHATEVER WAS IN THOSE SLOTS COMES OFF —
 * and comes off whole. A jacket worn over a vest that covered the torso and both arms takes
 * the vest off entirely, not just the torso: a set that has lost a slot to another piece is
 * not a set any more, and half a vest is not something the fiction can hold.
 *
 * Nothing is destroyed by any of it, so nothing is confirmed. Like the equipped deck, a
 * worn piece stays in the stash grid; it is only tagged.
 */
final class ArmourWearer
{
    public function wear(Character $character, InventoryItem $row): true|WearRefusal
    {
        if ($row->character_id !== $character->id) {
            return WearRefusal::NotOwned;
        }

        $slots = $row->item?->armour?->covers() ?? [];

        if ($slots === []) {
            return WearRefusal::NotArmour;
        }

        if ($row->isWorn()) {
            return WearRefusal::AlreadyWorn;
        }

        // Free to put on: not in a container, fitted, running or installed. The same question the
        // market, a shop and a container ask, so the ways of being spoken for stay one list.
        if (! InventoryItem::query()->loose()->whereKey($row->getKey())->exists()) {
            return WearRefusal::NotFree;
        }

        return DB::transaction(function () use ($character, $row, $slots): true {
            // Serialised per character, so two presses cannot both find a slot empty.
            Character::query()->whereKey($character->getKey())->lockForUpdate()->first();

            $wanted = array_map(static fn (ArmourSlot $slot): string => $slot->value, $slots);

            // Whoever holds any of these slots comes off, whole.
            $displaced = WornSlot::query()
                ->where('character_id', $character->getKey())
                ->whereIn('slot', $wanted)
                ->pluck('inventory_item_id')
                ->unique()
                ->all();

            if ($displaced !== []) {
                WornSlot::query()
                    ->where('character_id', $character->getKey())
                    ->whereIn('inventory_item_id', $displaced)
                    ->delete();
            }

            foreach ($wanted as $slot) {
                WornSlot::query()->create([
                    'character_id' => $character->getKey(),
                    'slot' => $slot,
                    'inventory_item_id' => $row->getKey(),
                ]);
            }

            return true;
        });
    }

    public function remove(Character $character, InventoryItem $row): true|WearRefusal
    {
        if ($row->character_id !== $character->id) {
            return WearRefusal::NotOwned;
        }

        $removed = WornSlot::query()
            ->where('character_id', $character->getKey())
            ->where('inventory_item_id', $row->getKey())
            ->delete();

        return $removed > 0 ? true : WearRefusal::NotWorn;
    }
}
