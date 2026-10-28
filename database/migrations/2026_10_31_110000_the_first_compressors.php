<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The first two Compressors, as authored in the item editor: KOMPRESSOR.EXE (on the market) and OmniStacker 5 (off the
 * market, kept by the Bureau of Network Integrity for loyalty). Each is a Utility in the `compressor` family adding a
 * percentage of base to `deck_storage` while it runs. An item whose slug already exists is left exactly as it is, so a copy
 * authored on the live site first is never overwritten.
 */
return new class extends Migration
{
    /** @var list<array<string, mixed>> */
    private const ITEMS = [
        [
            'slug' => 'kompressor', 'name' => 'KOMPRESSOR.EXE', 'tier' => 1, 'rarity' => 'common', 'base_cost' => 500, 'on_market' => true, 'seed_quantity' => 5,
            'flavor' => 'An old classic tool that, while outdated, still works. Very low footprint, but highly confusing to use. You have to know the exact paramaters to feed it, otherwise it just exists with this line: "Kompressor break your glowstick, Kompressor eat your candy". Usage statistics show an effective compression rate of somewhere between 5 and 8%.',
            'qflops' => 2.0, 'memory' => 3.0, 'storage' => 3.0, 'percent' => 7,
        ],
        [
            'slug' => 'omnistacker5', 'name' => 'OmniStacker 5', 'tier' => 2, 'rarity' => 'rare', 'base_cost' => 3000, 'on_market' => false, 'seed_quantity' => 0,
            'flavor' => 'OmniStacker 5 is the latest installment of this well known and beloved utility. It employs several proprietary tricks to compress and decompress data in real time on your disks, making it possible to cram more files onto the same hardware. OmniCorp claims a compression rate of 10-15%.',
            'qflops' => 3.0, 'memory' => 3.0, 'storage' => 4.0, 'percent' => 12,
        ],
    ];

    public function up(): void
    {
        // Game content for the live world: the suite builds its own rows.
        if (app()->runningUnitTests()) {
            return;
        }

        $now = now();

        foreach (self::ITEMS as $row) {
            if (DB::table('items')->where('slug', $row['slug'])->exists()) {
                continue;
            }

            $item = DB::table('items')->insertGetId([
                'slug' => $row['slug'], 'name' => $row['name'], 'flavor' => $row['flavor'], 'container' => 'data', 'tier' => $row['tier'],
                'rarity' => $row['rarity'], 'base_cost' => $row['base_cost'], 'on_market' => $row['on_market'], 'seed_quantity' => $row['seed_quantity'],
                'stackable' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);

            DB::table('programs')->insert([
                'item_id' => $item, 'kind' => 'utility', 'family' => 'compressor', 'qflops_cost' => $row['qflops'], 'memory_cost' => $row['memory'],
                'storage_size' => $row['storage'], 'background_process' => true, 'multithreaded' => false,
                'modifiers' => json_encode([['op' => 'percentage_of_base', 'amount' => $row['percent'], 'target' => ['id' => 'deck_storage']]]),
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // The Bureau of Network Integrity keeps OmniStacker 5 on its shelf: 35 loyalty, from standing 2, any desk grade.
        $bureau = DB::table('entities')->where('name', 'Bureau of Network Integrity')->value('id');
        $stacker = DB::table('items')->where('slug', 'omnistacker5')->value('id');

        if ($bureau !== null && $stacker !== null && ! DB::table('entity_offers')->where('entity_id', $bureau)->where('item_id', $stacker)->exists()) {
            DB::table('entity_offers')->insert([
                'entity_id' => $bureau, 'min_grade' => 1, 'max_grade' => 5, 'item_id' => $stacker, 'loyalty_price' => 35, 'min_standing' => 2,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('items')->whereIn('slug', array_column(self::ITEMS, 'slug'))->delete();
    }
};
