<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The Gap and Slipways, and The Greenhouse and Pusher Street, which were made in the development admin and never reached production.
 * Rows only, each skipped when its slug is already there (a deploy that made them by hand in the admin is not overwritten).
 * The Greenhouse's buy list is not here: `greenhouse:sync` writes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The test database (SQLite) is the starter city, which the tests count; this is production's content.
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        $districts = [
            0 => [
                'slug' => 'the_gap',
                'name' => 'The Gap',
                'description' => 'What\'s left of the old neighborhoods of Clement, Ridgewood and Hollow Hills. This used to be industrial parks, old housing, parkland, a few burbclaves and some pretty ambitious expansion up from Downtown - until the quake in \'76. The quake toppled or damaged half of the buildings and burst the levees, flooding the area. People fled, and The City has been dodging coughing up the money to reclaim and repair the area ever since. That leaves The Gap as a strange, urban wasteland where cracked roads and swamps mix with old car parks, flooded streets, half-finished skyscrapers and vegetation pushing up through the concrete cracks. People still live here of course, lots of people. It\'s cheap, unregulated and up for grabs if you care to put in the work to make it habitable, but it\'s also more or less cut off from the amenities of the city.',
                'color' => 'rose',
                'map_x' => 50,
                'map_y' => 20,
                'travel_weight' => '3.00',
            ],
            1 => [
                'slug' => 'slipways',
                'name' => 'Slipways',
                'description' => 'The Slipways is an old district, straddling both sides of the river.',
                'color' => 'sand',
                'map_x' => 59,
                'map_y' => 64,
                'travel_weight' => '1.00',
            ],
        ];
        $locations = [
            0 => [
                'district' => 'little-moscow',
                'slug' => 'greenhouse',
                'name' => 'The Greenhouse',
                'description' => 'On the roof of an eleven story building from last century sits an old greenhouse, where Auntie Zhang grows various plants used for traditional Chinese medicine. Many people in this neighborhood come visit the greenhouse to remedy various ills. The greenhouse is not, however, Auntie Zhang\'s main line of work - for that you\'ll have to step into her tiny office beside it. Auntie Zhang is a data trader. If you are discreet, and have data someone would rather you didn\'t have, Auntie Zhang will buy it and find a buyer down the line.',
                'map_x' => 25,
                'map_y' => 25,
                'discovery' => 'explorable',
                'concealment' => 'difficult',
                'required_flag' => null,
            ],
            1 => [
                'district' => 'knightsbridge-projects',
                'slug' => 'pusher_street',
                'name' => 'Pusher Street',
                'description' => 'Old RVs, tents and food stalls line this infamous street in Knightsbridge where the local street gang  Yardie Boys control the open drug trade. Pusher Street is so deep in Knightsbridge that City Police rarely raid it - people see them coming and Pusher Street gets evacuated, roadblocks go up and mayhem ensues. If you keep your wits about you and stay cool Pusher Street is where you can find almost anything in terms of off-label meds, research chems, recreational drugs, precursors and paraphernalia. If you act like a tourist you\'re likely to get scammed or robbed, and step out of line you\'ll quickly find yourself surrounded and outgunned.',
                'map_x' => 55,
                'map_y' => 60,
                'discovery' => 'explorable',
                'concealment' => 'trivial',
                'required_flag' => null,
            ],
        ];
        $links = [
            0 => [
                'a' => 'downtown',
                'b' => 'the_gap',
            ],
            1 => [
                'a' => 'knightsbridge-projects',
                'b' => 'the_gap',
            ],
            2 => [
                'a' => 'old-town',
                'b' => 'slipways',
            ],
            3 => [
                'a' => 'eastside',
                'b' => 'slipways',
            ],
            4 => [
                'a' => 'downtown',
                'b' => 'slipways',
            ],
            5 => [
                'a' => 'little-moscow',
                'b' => 'slipways',
            ],
        ];
        $stock = [
            0 => [
                'location' => 'pusher_street',
                'item' => 'rewire',
                'price' => 275,
                'max_stock' => 25,
                'restock_amount' => 5,
                'restock_hours' => 1,
            ],
            1 => [
                'location' => 'pusher_street',
                'item' => '4bta_40mg',
                'price' => 170,
                'max_stock' => 35,
                'restock_amount' => 7,
                'restock_hours' => 1,
            ],
            2 => [
                'location' => 'pusher_street',
                'item' => '4bta_100mg',
                'price' => 280,
                'max_stock' => 25,
                'restock_amount' => 5,
                'restock_hours' => 1,
            ],
        ];
        $meals = [
            0 => [
                'location' => 'pusher_street',
                'name' => 'Roast corn',
                'price' => 10,
                'meal_fullness' => '8.00',
                'meal_fatigue' => '6.00',
            ],
            1 => [
                'location' => 'pusher_street',
                'name' => 'Jerk Tofu',
                'price' => 20,
                'meal_fullness' => '22.00',
                'meal_fatigue' => '12.00',
            ],
            2 => [
                'location' => 'pusher_street',
                'name' => 'Fish Bammy',
                'price' => 20,
                'meal_fullness' => '23.00',
                'meal_fatigue' => '13.00',
            ],
            3 => [
                'location' => 'pusher_street',
                'name' => 'Bottle of Red Stripe',
                'price' => 10,
                'meal_fullness' => '7.00',
                'meal_fatigue' => '5.00',
            ],
        ];

        $now = now();

        foreach ($districts as $row) {
            if (! DB::table('districts')->where('slug', $row['slug'])->exists()) {
                DB::table('districts')->insert($row + ['created_at' => $now, 'updated_at' => $now]);
            }
        }

        $district = fn (string $slug): int => (int) DB::table('districts')->where('slug', $slug)->value('id');

        foreach ($links as $link) {
            [$a, $b] = [$district($link['a']), $district($link['b'])];
            $pair = ['district_a_id' => min($a, $b), 'district_b_id' => max($a, $b)];

            if ($a > 0 && $b > 0 && ! DB::table('district_links')->where($pair)->exists()) {
                DB::table('district_links')->insert($pair);
            }
        }

        foreach ($locations as $row) {
            if (! DB::table('locations')->where('slug', $row['slug'])->exists()) {
                $row['district_id'] = $district($row['district']);
                unset($row['district']);
                DB::table('locations')->insert($row + ['created_at' => $now, 'updated_at' => $now]);
            }
        }

        foreach ($stock as $row) {
            $location = (int) DB::table('locations')->where('slug', $row['location'])->value('id');
            $item = (int) DB::table('items')->where('slug', $row['item'])->value('id');

            if ($location > 0 && $item > 0 && ! DB::table('shop_items')->where(['location_id' => $location, 'item_id' => $item])->exists()) {
                DB::table('shop_items')->insert([
                    'location_id' => $location, 'item_id' => $item, 'price' => $row['price'], 'max_stock' => $row['max_stock'],
                    'stock' => $row['max_stock'], 'restock_amount' => $row['restock_amount'], 'restock_hours' => $row['restock_hours'],
                    'restocked_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        foreach ($meals as $row) {
            $location = (int) DB::table('locations')->where('slug', $row['location'])->value('id');

            if ($location > 0 && ! DB::table('location_meals')->where(['location_id' => $location, 'name' => $row['name']])->exists()) {
                unset($row['location']);
                DB::table('location_meals')->insert($row + ['location_id' => $location, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    /** Content someone may since have edited: not taken back. */
    public function down(): void {}
};
