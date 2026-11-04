<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The two Antivirals (docs/CYBERSPACE.md, *Virus*): background Utility programs whose modifiers lower `virus_lifetime`, so a
 * virus clears sooner for as long as they run. An item whose slug already exists is left exactly as it is, so a copy
 * authored on the live site first is never overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach ([
            [
                'slug' => 'condom_0_49_1', 'name' => 'Condom 0.49.1', 'rarity' => 'common', 'base_cost' => 300, 'seed_quantity' => 5,
                'flavor' => 'Anti-viral toolbox on a budget. It used to be pretty good, before updates started coming less often. It\'s still better than nothing, though.',
                'cost' => 0.5, 'reduction' => -33,
            ],
            [
                'slug' => 'purge_pro', 'name' => 'Purge Pro', 'rarity' => 'uncommon', 'base_cost' => 1500, 'seed_quantity' => 3,
                'flavor' => 'Commercial-grade infection control. It sits quietly beside everything else, quarantines whatever got in, and strips it out in half the time.',
                'cost' => 1.0, 'reduction' => -50,
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
                'item_id' => $id, 'kind' => 'utility', 'qflops_cost' => $row['cost'], 'memory_cost' => $row['cost'],
                'storage_size' => $row['cost'], 'background_process' => true, 'multithreaded' => false,
                'modifiers' => json_encode([['op' => 'percentage_of_base', 'amount' => $row['reduction'], 'target' => ['id' => 'virus_lifetime']]]),
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('items')->whereIn('slug', ['condom_0_49_1', 'purge_pro'])->delete();
    }
};
