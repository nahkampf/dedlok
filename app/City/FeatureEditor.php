<?php

declare(strict_types=1);

namespace App\City;

use App\Models\Location;

/**
 * The only writer of `shop_items` and `clinic_services`.
 *
 * Both are replaced wholesale, as an archetype's loadout is, because the form
 * submits the set it wants and nothing references a row's id. If a listing ever
 * acquires something worth keeping — a stock count, a sales figure — this has to
 * become a diff. Call it inside a transaction with the location's own save.
 */
final class FeatureEditor
{
    /**
     * @param  list<array{item_id: int, price: int|null}>  $shop
     * @param  array<string, int>  $clinic  service value => creds per point
     */
    public static function apply(Location $location, array $shop, array $clinic): void
    {
        $location->shopItems()->delete();
        $location->clinicServices()->delete();

        if ($shop !== []) {
            $location->shopItems()->createMany($shop);
        }

        foreach ($clinic as $service => $price) {
            $location->clinicServices()->create(['service' => $service, 'price' => $price]);
        }
    }
}
