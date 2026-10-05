<?php

declare(strict_types=1);

namespace App\Combat;

use App\Models\Ammunition;
use App\Models\Item;

/**
 * The only writer of the `ammunition` table. Always clears the side an item stopped being, like
 * `DrugEditor`. Nothing points at the row but the item, so there is nothing else to keep honest.
 */
final class AmmunitionEditor
{
    /** @param  array<string, mixed>|null  $columns  everything but item_id; null means this is not ammunition */
    public static function apply(Item $item, ?array $columns): void
    {
        // A round that stops being ammunition, or changes calibre, no longer fits what it is loaded in:
        // every weapon holding it hands it back to its owner's stash first.
        if ($item->ammunition !== null && $item->ammunition->calibre !== ($columns['calibre'] ?? null)) {
            WeaponLoader::emptyEveryWeaponHolding($item);
        }

        if ($columns === null) {
            $item->ammunition()->delete();
        } else {
            Ammunition::updateOrCreate(['item_id' => $item->getKey()], $columns);
        }

        $item->unsetRelation('ammunition');
    }
}
