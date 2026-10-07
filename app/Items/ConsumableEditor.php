<?php

declare(strict_types=1);

namespace App\Items;

use App\Models\Consumable;
use App\Models\Item;

/**
 * The only writer of the `consumables` table.
 *
 * `DrugEditor`'s twin, with the same call: **it always clears the side an item stopped being.** An item re-saved with
 * no effects loses its row, because a key merely absent from an update would leave the old one sitting there and a
 * thing that is no longer a medkit would still be usable. Nothing here to diff: a copy in somebody's stash points at
 * the ITEM, so replacing the row strands nothing.
 */
final class ConsumableEditor
{
    /**
     * @param  list<array{kind: string, amount: int}>  $effects  empty means this is not a consumable
     */
    public static function apply(Item $item, array $effects): void
    {
        if ($effects === []) {
            $item->consumable()->delete();
            $item->unsetRelation('consumable');

            return;
        }

        Consumable::updateOrCreate(['item_id' => $item->getKey()], ['effects' => array_values($effects)]);
        $item->unsetRelation('consumable');
    }
}
