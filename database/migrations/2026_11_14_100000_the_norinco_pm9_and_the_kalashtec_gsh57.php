<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two pistols made in the development database that production lacks: the Norinco PM9 and the Kalashtec GSh-57, each an
 * item plus its weapons row, off the market. A pistol whose slug already exists is left alone, so this is safe to run
 * on a database that got one by hand. No schema changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $pistols = [
            [
                'slug' => 'norinco_pm9',
                'name' => 'Norinco PM9',
                'flavor' => 'The civilian models of the Norinco guns are known for two things: their cheap price, and their low quality. The PM9 is very common for budget minded shooters, cash strapped rentacop outfits and low-level street trash. Chambered in 9mm.',
                'base_cost' => 780,
                'seed_quantity' => 9,
                'accuracy' => -4,
                'magazine' => 10,
            ],
            [
                'slug' => 'kalashtec-gsh57',
                'name' => 'Kalashtec GSh-57',
                'flavor' => 'A high-tech pistol originally designed for certain special units, and now turned export success to various pistol aficionados in the West. GSh-57 is known for its exacting standards, quality control and performance in extreme environments. The Chambered in 9mm.',
                'base_cost' => 2900,
                'seed_quantity' => 5,
                'accuracy' => 0,
                'magazine' => 9,
            ],
        ];

        foreach ($pistols as $pistol) {
            if (DB::table('items')->where('slug', $pistol['slug'])->exists()) {
                continue;
            }

            $id = DB::table('items')->insertGetId([
                'slug' => $pistol['slug'],
                'name' => $pistol['name'],
                'flavor' => $pistol['flavor'],
                'container' => 'physical',
                'tier' => 1,
                'rarity' => 'common',
                'stackable' => 0,
                'weight' => 1.00,
                'size' => 1.0,
                'capacity' => 0.0,
                'base_cost' => $pistol['base_cost'],
                'on_market' => 0,
                'seed_quantity' => $pistol['seed_quantity'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('weapons')->insert([
                'item_id' => $id,
                'class' => 'pistol',
                'accuracy' => $pistol['accuracy'],
                'rate' => 1,
                'magazine' => $pistol['magazine'],
                'calibre' => '9mm',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Either pistol may have been bought, granted or tuned since; there is nothing safe to take back.
    }
};
