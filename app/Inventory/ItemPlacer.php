<?php

declare(strict_types=1);

namespace App\Inventory;

use App\Decks\Storage;
use App\Models\Character;
use App\Models\InventoryItem;
use Illuminate\Support\Facades\DB;

/**
 * Putting an item into a container, and taking it back out.
 *
 * The container is an inventory row like any other — a memory card is a
 * physical item the player carries, and what is written on it are data rows
 * whose parent_id points at the card. Nothing else in the app moves rows
 * between containers, so every rule about what may hold what lives here.
 *
 * Depth is capped at one on purpose. A card cannot go inside a card, which
 * means the structure cannot contain a cycle, which means none of this has to
 * walk a tree defensively.
 */
final class ItemPlacer
{
    /**
     * Move $row into $into, or out into the loose pile when $into is null.
     */
    public function place(Character $character, InventoryItem $row, ?InventoryItem $into): PlacementResult
    {
        if ($row->character_id !== $character->id) {
            return PlacementResult::refused(PlacementRefusal::NotOwned);
        }

        if ($into === null) {
            return $this->eject($character, $row);
        }

        if ($into->character_id !== $character->id) {
            return PlacementResult::refused(PlacementRefusal::NotOwned);
        }

        // Its own pocket. Not a container question, an identity one.
        if ($into->getKey() === $row->getKey()) {
            return PlacementResult::refused(PlacementRefusal::ContainersDoNotNest);
        }

        if (! $into->item->isContainer()) {
            return PlacementResult::refused(PlacementRefusal::NotAContainer);
        }

        // Both halves of the depth cap: the target must be loose, and the
        // thing being put away must not be a container itself.
        if ($into->parent_id !== null || $row->item->isContainer()) {
            return PlacementResult::refused(PlacementRefusal::ContainersDoNotNest);
        }

        if (! $into->item->canHold($row->item)) {
            return PlacementResult::refused(PlacementRefusal::WrongKind);
        }

        return DB::transaction(function () use ($row, $into): PlacementResult {
            // Re-read both under a lock: capacity is a sum over rows that
            // another request could be adding to at the same moment.
            $locked = InventoryItem::whereKey($row->getKey())->lockForUpdate()->first();
            $target = InventoryItem::whereKey($into->getKey())->lockForUpdate()->first();

            if ($locked === null || $target === null) {
                return PlacementResult::refused(PlacementRefusal::NotOwned);
            }

            $target->load('children.item');

            $needed = (int) $locked->item->size * $locked->quantity;

            // Room already taken by this row does not count against it.
            $free = $target->remainingCapacity()
                + ($locked->parent_id === $target->getKey() ? $needed : 0);

            if ($needed > $free) {
                return PlacementResult::refused(PlacementRefusal::NoRoom);
            }

            return PlacementResult::placed($this->moveInto($locked, $target->getKey()));
        });
    }

    /** Take a row out of whatever holds it. Already-loose rows are a no-op. */
    private function eject(Character $character, InventoryItem $row): PlacementResult
    {
        if ($row->parent_id === null) {
            return PlacementResult::placed($row);
        }

        // Out of a shard is onto the deck, and the deck has to hold it.
        if (! Storage::fits($character, Storage::sizeOfRow($row))) {
            return PlacementResult::refused(PlacementRefusal::NoStorage);
        }

        return DB::transaction(fn () => PlacementResult::placed($this->moveInto($row, null)));
    }

    /**
     * Repoint a row, merging it into the pile already there if there is one.
     *
     * Without the merge this is a unique-key violation waiting to happen: two
     * stacks of the same stackable item in the same container are one pile by
     * definition, and the index says so.
     */
    private function moveInto(InventoryItem $row, ?int $parentId): InventoryItem
    {
        $existing = $row->item->stackable
            ? InventoryItem::query()
                ->where('character_id', $row->character_id)
                ->where('item_id', $row->item_id)
                ->where('parent_id', $parentId)
                ->whereKeyNot($row->getKey())
                ->lockForUpdate()
                ->first()
            : null;

        if ($existing === null) {
            $row->parent_id = $parentId;
            $row->save();

            return $row;
        }

        $existing->quantity += $row->quantity;
        $existing->save();
        $row->delete();

        return $existing;
    }
}
