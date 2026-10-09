<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The two locators, as authored in the item editor: Azimuth (a Locator) and lOCO_mOTiVE 0.9.1 (a Pathfinder, which also
 * walks you to what it found). Both are on the market. An item whose slug already exists is left exactly as it is, so a
 * copy authored on the live site first is never overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach ([
            [
                'slug' => 'azimuth', 'name' => 'Azimuth', 'rarity' => 'common', 'base_cost' => 250, 'seed_quantity' => 5,
                'flavor' => "A simple program to direct to you the nearest system of a specific type. It can only locate systems you've previously detected. Grades can only be found if you have recent scan data.",
                'kind' => 'locator', 'cost' => 0.5,
            ],
            [
                'slug' => 'loco_motive', 'name' => 'lOCO_mOTiVE 0.9.1', 'rarity' => 'uncommon', 'base_cost' => 2000, 'seed_quantity' => 3,
                'flavor' => 'A very sloppy hack, but it works: point your locator at a target and hit "Autotraverse" and it tugs you along for the ride until you reach the correct node.',
                'kind' => 'pathfinder', 'cost' => 1.0,
            ],
        ] as $row) {
            if (DB::table('items')->where('slug', $row['slug'])->exists()) {
                continue;
            }

            $id = DB::table('items')->insertGetId([
                'slug' => $row['slug'], 'name' => $row['name'], 'flavor' => $row['flavor'], 'container' => 'data', 'tier' => 1,
                'rarity' => $row['rarity'], 'stackable' => true, 'base_cost' => $row['base_cost'], 'on_market' => true,
                'seed_quantity' => $row['seed_quantity'], 'created_at' => $now, 'updated_at' => $now,
            ]);

            DB::table('programs')->insert([
                'item_id' => $id, 'kind' => $row['kind'], 'qflops_cost' => $row['cost'], 'memory_cost' => $row['cost'],
                'storage_size' => $row['cost'], 'background_process' => true, 'multithreaded' => false,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('items')->whereIn('slug', ['azimuth', 'loco_motive'])->delete();
    }
};
