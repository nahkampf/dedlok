<?php

declare(strict_types=1);

namespace App\City;

use App\Models\Location;
use Carbon\CarbonImmutable;

/**
 * The only writer of a place's listings: `shop_items`, `location_buys`,
 * `clinic_services` and `clinic_surgeries`.
 *
 * SHOP LISTINGS ARE A DIFF, because a listing carries a stock count worth
 * keeping: saving the editor must not refill every shelf. An existing listing
 * keeps its stock (clamped to a lowered maximum) and its restock boundary, a
 * new one starts full, and a removed one goes. The buy list and the clinic's
 * services hold nothing to keep and are replaced wholesale. Call it inside a
 * transaction with the location's own save.
 */
final class FeatureEditor
{
    /**
     * @param  list<array{item_id: int, price: int|null, max_stock: int|null, restock_amount: int, restock_hours: int|null}>  $shop
     * @param  array<string, int>  $clinic  service value => creds per point
     * @param  list<array{item_id: int, price: int|null}>  $buys
     * @param  array{rating: int, install_price: int, removal_price: int}|null  $surgery
     */
    public static function apply(Location $location, array $shop, array $clinic, array $buys = [], ?array $surgery = null): void
    {
        $now = CarbonImmutable::now();
        $existing = $location->shopItems()->get()->keyBy('item_id');
        $wanted = array_column($shop, 'item_id');

        $location->shopItems()->whereNotIn('item_id', $wanted)->delete();

        foreach ($shop as $row) {
            $listing = $existing[$row['item_id']] ?? null;
            $max = $row['max_stock'];

            if ($listing === null) {
                $location->shopItems()->create($row + ['stock' => $max ?? 0, 'restocked_at' => $now]);

                continue;
            }

            $listing->update($row + [
                'stock' => $max === null ? $listing->stock : min($listing->stock, $max),
                'restocked_at' => $listing->restocked_at ?? $now,
            ]);
        }

        $location->buys()->delete();
        $location->clinicServices()->delete();

        if ($buys !== []) {
            $location->buys()->createMany($buys);
        }

        $location->surgery()->delete();

        if ($surgery !== null) {
            $location->surgery()->create($surgery);
        }

        foreach ($clinic as $service => $price) {
            $location->clinicServices()->create(['service' => $service, 'price' => $price]);
        }
    }
}
