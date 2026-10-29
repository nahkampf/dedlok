<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A meal at a safehouse takes 2 points less fatigue off (to a floor of 0). A home with no table (0) stays without one.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('safehouses')->where('meal_fatigue', '>', 0)->get(['id', 'meal_fatigue']) as $home) {
            DB::table('safehouses')->where('id', $home->id)->update(['meal_fatigue' => max(0, $home->meal_fatigue - 2)]);
        }
    }

    public function down(): void
    {
        DB::table('safehouses')->where('meal_fatigue', '>', 0)->increment('meal_fatigue', 2);
    }
};
