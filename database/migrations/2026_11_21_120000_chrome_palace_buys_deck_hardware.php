<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Chrome Palace buys every item of deck hardware (decks and every module kind) at 70% of its cost, with no cap. Data only:
 * an existing row for the pair is set to these terms, none is duplicated. Hardware added later is not covered.
 */
return new class extends Migration
{
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

        foreach (DB::table('items')->whereNotNull('hardware')->pluck('id') as $item) {
            DB::table('location_buys')->updateOrInsert(
                ['location_id' => $shop, 'item_id' => $item],
                ['percent' => 70, 'max_quantity' => null, 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }

    public function down(): void
    {
        $shop = DB::table('locations')->where('name', 'Chrome Palace')->value('id');

        DB::table('location_buys')->where('location_id', $shop)->where('percent', 70)
            ->whereIn('item_id', DB::table('items')->whereNotNull('hardware')->select('id'))->delete();
    }
};
