<?php

declare(strict_types=1);

namespace App\Equipment;

use App\Models\Character;
use App\Models\InventoryItem;
use Illuminate\Support\Facades\DB;

/**
 * Puts things on the belt and takes them off. Drugs and consumables go on it: what is meant to be used in a hurry. The only writer of `inventory_items.belt_slot`.
 *
 * WHICH SLOT is not the player's question, as which socket is not for `ModuleFitter::fitToDeck()`: the first free
 * one takes it. A slot holds one inventory ROW, so a pile of stims is one slot however many are in it, and the
 * whole pile goes on the belt.
 *
 * Nothing is destroyed, so nothing is confirmed. A belted row stays in the stash grid, tagged, like worn armour.
 *
 * TAKING IT OFF MERGES. A belted pile has no stack key (it must not merge with the loose pile of the same item
 * while it is on the belt), so when it comes off and a loose pile exists, the two become one, as stopping a
 * running program does. Anything else would collide on the unique `stack_key` the moment somebody saved it.
 */
final class BeltEquipper
{
    public function put(Character $character, InventoryItem $row): true|BeltRefusal
    {
        if ($row->character_id !== $character->id) {
            return BeltRefusal::NotOwned;
        }

        if ($row->item?->drug === null && $row->item?->consumable === null) {
            return BeltRefusal::NotBeltable;
        }

        if ($row->isBelted()) {
            return BeltRefusal::AlreadyBelted;
        }

        if (! InventoryItem::query()->loose()->whereKey($row->getKey())->exists()) {
            return BeltRefusal::NotFree;
        }

        return DB::transaction(function () use ($character, $row): true|BeltRefusal {
            // Serialised per character, so two presses cannot both find a slot free.
            Character::query()->whereKey($character->getKey())->lockForUpdate()->first();

            $taken = InventoryItem::query()
                ->where('character_id', $character->getKey())
                ->whereNotNull('belt_slot')
                ->pluck('belt_slot')
                ->map(fn ($slot): int => (int) $slot)
                ->all();

            foreach (range(1, (int) config('equipment.belt_slots')) as $slot) {
                if (! in_array($slot, $taken, true)) {
                    $row->belt_slot = $slot;
                    $row->save();

                    return true;
                }
            }

            return BeltRefusal::BeltFull;
        });
    }

    public function take(Character $character, InventoryItem $row): true|BeltRefusal
    {
        if ($row->character_id !== $character->id) {
            return BeltRefusal::NotOwned;
        }

        if (! $row->isBelted()) {
            return BeltRefusal::NotBelted;
        }

        DB::transaction(function () use ($row): void {
            $row->belt_slot = null;

            $pile = $row->item?->stackable
                ? InventoryItem::query()
                    ->where('character_id', $row->character_id)
                    ->where('item_id', $row->item_id)
                    ->whereNull('belt_slot')
                    ->whereNull('running_on_deck_id')
                    ->whereNull('parent_id')
                    ->whereNotNull('stack_key')
                    ->whereKeyNot($row->getKey())
                    ->first()
                : null;

            if ($pile === null) {
                $row->save();

                return;
            }

            $pile->quantity += $row->quantity;
            $pile->save();
            $row->delete();
        });

        return true;
    }
}
