<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Jumper: a Utility that jumps (`programs.jumps`), so a Jump button shows beside addresses while it runs on the equipped deck.
 * Tier 1, uncommon, off the market and not craftable (found). An existing item with the slug is left exactly as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Game content for the live world: the suite builds its own rows.
        if (app()->runningUnitTests() || DB::table('items')->where('slug', 'jumper')->exists()) {
            return;
        }

        $now = now();

        $item = DB::table('items')->insertGetId([
            'slug' => 'jumper', 'name' => 'Jumper',
            'flavor' => 'A simple script that extracts node addresses from links and gives you the option of directly jumping to them. A very convenient little utility.',
            'container' => 'data', 'tier' => 1, 'rarity' => 'uncommon', 'base_cost' => 0, 'on_market' => false, 'seed_quantity' => 0,
            'stackable' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        DB::table('programs')->insert([
            'item_id' => $item, 'kind' => 'utility', 'qflops_cost' => 0.2, 'memory_cost' => 0.3, 'storage_size' => 0.3,
            'background_process' => true, 'multithreaded' => false, 'jumps' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('items')->where('slug', 'jumper')->delete();
    }
};
