<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Path of Repentance is handed out by Golden Path's recruiters, so it leaves the market and goes on their shelf: 15
 * loyalty, no standing needed, at a desk of any grade. Entity and item are found by name and slug, since their ids
 * differ between databases, and nothing is touched when either is missing or the offer is already there.
 */
return new class extends Migration
{
    public function up(): void
    {
        $item = DB::table('items')->where('slug', 'path_of_repentance')->value('id');
        $entity = DB::table('entities')->where('name', 'Golden Path')->value('id');

        if ($item === null) {
            return;
        }

        DB::table('items')->where('id', $item)->update(['on_market' => false, 'seed_quantity' => 0, 'updated_at' => now()]);

        if ($entity === null || DB::table('entity_offers')->where('entity_id', $entity)->where('item_id', $item)->exists()) {
            return;
        }

        DB::table('entity_offers')->insert([
            'entity_id' => $entity, 'item_id' => $item, 'min_grade' => 1, 'max_grade' => 5,
            'loyalty_price' => 15, 'min_standing' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $item = DB::table('items')->where('slug', 'path_of_repentance')->value('id');
        $entity = DB::table('entities')->where('name', 'Golden Path')->value('id');

        $item !== null && $entity !== null && DB::table('entity_offers')->where('entity_id', $entity)->where('item_id', $item)->delete();
    }
};
