<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Aegis Compute 14 and Veritas Model 9100 leave the market (off it, their shelf rows gone) and are sold at Chrome Palace
 * instead, two on the shelf and one restocked every five hours, like the other Socket G CPUs there. Data only.
 */
return new class extends Migration
{
    /** slug => Chrome Palace price (about 1.15 x base cost, as its other CPUs are). */
    private const CPUS = ['aegis_kern14' => 7400, 'veritas_model9100' => 10200];

    public function up(): void
    {
        // Game content for the live world: the suite builds its own rows.
        if (app()->runningUnitTests()) {
            return;
        }

        $shop = DB::table('locations')->where('name', 'Chrome Palace')->value('id');

        if ($shop === null) {
            return;
        }

        foreach (self::CPUS as $slug => $price) {
            $item = DB::table('items')->where('slug', $slug)->value('id');

            if ($item === null) {
                continue;
            }

            DB::table('items')->where('id', $item)->update(['on_market' => false, 'updated_at' => now()]);
            DB::table('market_stock')->where('item_id', $item)->delete();

            DB::table('shop_items')->updateOrInsert(
                ['location_id' => $shop, 'item_id' => $item],
                ['price' => $price, 'max_stock' => 2, 'stock' => 2, 'restock_amount' => 1, 'restock_hours' => 5, 'restocked_at' => now(), 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }

    public function down(): void
    {
        $items = DB::table('items')->whereIn('slug', array_keys(self::CPUS))->pluck('id');
        $shop = DB::table('locations')->where('name', 'Chrome Palace')->value('id');

        DB::table('shop_items')->where('location_id', $shop)->whereIn('item_id', $items)->delete();
        DB::table('items')->whereIn('id', $items)->update(['on_market' => true]);
    }
};
