<?php

declare(strict_types=1);

namespace App\City;

use App\Admin\BuyListEditor;
use App\Models\Location;
use Carbon\CarbonImmutable;

/**
 * The only writer of a place's listings: `shop_items`, `location_buys`,
 * `clinic_services`, `clinic_surgeries` and `location_meals`.
 *
 * SHOP LISTINGS AND THE BUY LIST ARE A DIFF, because a listing carries a count worth
 * keeping: saving the editor must not refill every shelf or reset a buyer's limit. An existing listing
 * keeps its stock (clamped to a lowered maximum) and its restock boundary, a
 * new one starts full, and a removed one goes. The clinic's
 * services hold nothing to keep and are replaced wholesale. Call it inside a
 * transaction with the location's own save.
 */
final class FeatureEditor
{
    /**
     * @param  list<array{item_id: int, price: int|null, max_stock: int|null, restock_amount: int, restock_hours: int|null}>  $shop
     * @param  array<string, int>  $clinic  service value => creds per point
     * @param  list<array{item_id: int, percent: int, max_quantity: int|null}>  $buys
     * @param  array{rating: int, install_price: int, removal_price: int}|null  $surgery
     * @param  list<array{name: string, price: int, meal_fullness: float, meal_fatigue: float}>  $meals  the menu, replaced wholesale
     */
    public static function apply(Location $location, array $shop, array $clinic, array $buys = [], ?array $surgery = null, array $meals = []): void
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

        BuyListEditor::sync($location->buys(), $buys);
        $location->clinicServices()->delete();

        $location->meals()->delete();

        foreach ($meals as $meal) {
            $location->meals()->create($meal);
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
