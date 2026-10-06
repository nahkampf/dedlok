<?php

declare(strict_types=1);

namespace App\Combat;

use App\Models\Armour;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\WornSlot;

/**
 * The only writer of the `armour` table.
 *
 * `DrugEditor`'s twin, and it makes the same call: **it always clears the side an item
 * stopped being.** An item re-saved with no slots loses its armour row, because a key
 * merely absent from an update would leave the old one sitting there.
 *
 * It also keeps what is WORN honest. A piece that stops being armour comes off everybody;
 * a piece whose coverage is edited loses the worn slots it no longer covers. It does not
 * ADD slots to anyone already wearing it — that is a player's choice, made by wearing it
 * again.
 */
final class ArmourEditor
{
    /**
     * @param  list<string>  $slots  WearSlot values (or ArmourSlot values, which name their group); empty
     *                               means this is not armour. Stored as the hit locations they expand to.
     */
    public static function apply(Item $item, array $slots): void
    {
        $slots = WearSlot::expand($slots);
        $rows = InventoryItem::query()->where('item_id', $item->getKey())->pluck('id');

        if ($slots === []) {
            $item->armour()->delete();
            WornSlot::query()->whereIn('inventory_item_id', $rows)->delete();
            $item->unsetRelation('armour');

            return;
        }

        Armour::updateOrCreate(['item_id' => $item->getKey()], ['slots' => $slots]);

        WornSlot::query()->whereIn('inventory_item_id', $rows)->whereNotIn('slot', $slots)->delete();
        $item->unsetRelation('armour');
    }
}
