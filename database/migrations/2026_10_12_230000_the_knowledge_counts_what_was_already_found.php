<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Knowledge counts locations found by exploring, so a player who found some before the metric existed starts
 * from what they already have. The achievement itself is handed out the next time the metric moves.
 */
return new class extends Migration
{
    public function up(): void
    {
        $counts = DB::table('character_locations')
            ->join('characters', 'characters.id', '=', 'character_locations.character_id')
            ->selectRaw('characters.user_id as user_id, count(*) as found')
            ->groupBy('characters.user_id')
            ->get();

        foreach ($counts as $row) {
            DB::table('achievement_progress')->updateOrInsert(
                ['user_id' => $row->user_id, 'metric' => 'locations_discovered'],
                ['value' => $row->found],
            );
        }
    }

    public function down(): void
    {
        DB::table('achievement_progress')->where('metric', 'locations_discovered')->delete();
    }
};
