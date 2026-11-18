<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Scan File item gets a list price of 25, so a shop that buys at a percentage of list price pays something for one.
 * (A decrypted or sealed file is priced by its own rolled value, and a component by the file it came from; only the
 * Scan File reads `base_cost`.) A copy with no Scan File item is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('items')->where('slug', 'scan-file')->update(['base_cost' => 25, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('items')->where('slug', 'scan-file')->update(['base_cost' => 0, 'updated_at' => now()]);
    }
};
