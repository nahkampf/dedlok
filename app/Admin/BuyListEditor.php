<?php

declare(strict_types=1);

namespace App\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The only writer of a buy list: a location's `location_buys` or an entity's `entity_buys`.
 *
 * A DIFF, because a listing carries a count worth keeping: saving the editor must not hand a buyer a fresh limit.
 * An existing listing keeps `bought` and its window; a new one starts a window now; a removed one goes. Call it
 * inside a transaction with the owner's own save.
 */
final class BuyListEditor
{
    /**
     * @param  HasMany<*, *>  $buys
     * @param  list<array<string, mixed>>  $rows  each with `item_id`, `percent`, `max_quantity` (and a grade range for an entity)
     */
    public static function sync(HasMany $buys, array $rows): void
    {
        $existing = $buys->get()->keyBy('item_id');

        $buys->whereNotIn('item_id', array_column($rows, 'item_id'))->delete();

        foreach ($rows as $row) {
            $listing = $existing[$row['item_id']] ?? null;

            if ($listing === null) {
                $buys->create($row + ['bought' => 0, 'window_started_at' => CarbonImmutable::now()]);

                continue;
            }

            // A lowered limit does not leave more already taken than it allows.
            $listing->update($row + ['bought' => $row['max_quantity'] === null ? $listing->bought : min($listing->bought, $row['max_quantity'])]);
        }
    }
}
