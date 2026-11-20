<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Nobody bought Memory Dump. Five entities now do, at 80% of market value, in the grade range every other component buyer
 * uses (1-5) and with no cap. Data only: an existing row for the pair is set to these terms, none is duplicated.
 */
return new class extends Migration
{
    private const BUYERS = ['Bureau of Network Integrity', 'Joint Signals Command', 'Lockjaw', 'Ninth Circle Security', 'TAO'];

    public function up(): void
    {
        // Game content for the live world: the suite builds its own rows.
        if (app()->runningUnitTests()) {
            return;
        }

        $item = DB::table('items')->where('slug', 'memory-dump')->value('id');

        if ($item === null) {
            return;
        }

        foreach (self::BUYERS as $name) {
            $entity = DB::table('entities')->where('name', $name)->value('id');

            if ($entity === null) {
                continue;
            }

            DB::table('entity_buys')->updateOrInsert(
                ['entity_id' => $entity, 'item_id' => $item],
                ['min_grade' => 1, 'max_grade' => 5, 'percent' => 80, 'max_quantity' => null, 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }

    public function down(): void
    {
        $item = DB::table('items')->where('slug', 'memory-dump')->value('id');

        DB::table('entity_buys')->where('item_id', $item)->where('percent', 80)
            ->whereIn('entity_id', DB::table('entities')->whereIn('name', self::BUYERS)->select('id'))->delete();
    }
};
