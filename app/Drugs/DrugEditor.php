<?php

declare(strict_types=1);

namespace App\Drugs;

use App\Models\Drug;
use App\Models\Item;

/**
 * The only writer of the `drugs` table.
 *
 * `App\Programs\ProgramEditor`'s twin, and it takes the same decision: **it
 * always clears the side an item stopped being.** An item re-saved without a
 * family loses its drug row, because a key merely absent from an update would
 * leave the old one sitting there — and something that is no longer a drug
 * should not still be swallowable.
 *
 * Nothing here to diff. A dose points at the ITEM rather than at this row, so
 * replacing it wholesale strands nothing; what is already in somebody keeps
 * working and starts reading the new numbers, which is the same thing that
 * happens when any other definition is retuned under its instances.
 */
final class DrugEditor
{
    /**
     * @param  array<string, mixed>  $columns  everything but item_id and family
     */
    public static function apply(Item $item, ?Family $family, array $columns = []): void
    {
        if ($family === null) {
            self::clear($item);

            return;
        }

        Drug::updateOrCreate(
            ['item_id' => $item->id],
            $columns + ['family' => $family],
        );

        $item->unsetRelation('drug');
    }

    /** Stop being a drug. */
    public static function clear(Item $item): void
    {
        $item->drug()->delete();
        $item->unsetRelation('drug');
    }
}
