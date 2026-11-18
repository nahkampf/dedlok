<?php

declare(strict_types=1);

namespace App\City;

use App\Models\Item;
use App\Models\Location;
use App\Models\LocationBuy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Keeps The Greenhouse's buy list in step with `city.greenhouse`: every extracted file and component at a share of its own
 * value, and a Scan File at a flat few creds. Only the items named there are touched: any other listing the place has is left
 * as the editor made it, and an existing listing keeps its count and window.
 */
final class GreenhouseBuys
{
    /**
     * @return array{synced: int, missing: list<string>, notes: list<string>} how many listings were written, the slugs with
     *                                                                        no item behind them, and anything worth saying
     */
    public function sync(): array
    {
        $place = Location::query()->where('slug', (string) Config::get('city.greenhouse.location'))->first();

        if ($place === null) {
            return ['synced' => 0, 'missing' => [], 'notes' => ['There is no location with the Greenhouse slug.']];
        }

        $notes = [];
        $wanted = [];

        foreach ((array) Config::get('city.greenhouse.files') as $slug) {
            $wanted[$slug] = (int) Config::get('city.greenhouse.files_percent');
        }

        $scan = (array) Config::get('city.greenhouse.scan_file');
        $price = (int) Item::query()->where('slug', $scan['slug'])->value('base_cost');

        if ($price < 1) {
            $notes[] = "{$scan['slug']} has no list price, so it is not bought.";
        } else {
            $percent = (int) ceil($scan['pays'] * 100 / $price);

            if (intdiv($price * $percent, 100) !== (int) $scan['pays']) {
                $notes[] = "{$scan['slug']} cannot be bought for exactly {$scan['pays']} on a list price of {$price}: it pays ".intdiv($price * $percent, 100).'.';
            }

            $wanted[$scan['slug']] = $percent;
        }

        $items = Item::query()->whereIn('slug', array_keys($wanted))->pluck('id', 'slug');
        $synced = 0;

        DB::transaction(function () use ($place, $wanted, $items, &$synced): void {
            foreach ($wanted as $slug => $percent) {
                if (! isset($items[$slug])) {
                    continue;
                }

                $listing = LocationBuy::query()->firstOrNew(['location_id' => $place->getKey(), 'item_id' => $items[$slug]]);
                $listing->forceFill(['percent' => $percent, 'max_quantity' => null] + ($listing->exists ? [] : ['bought' => 0, 'window_started_at' => CarbonImmutable::now()]))->save();
                $synced++;
            }
        });

        return ['synced' => $synced, 'missing' => array_values(array_diff(array_keys($wanted), $items->keys()->all())), 'notes' => $notes];
    }
}
