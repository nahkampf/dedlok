<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two slips in the NJORD encounters, fixed where they live (the rows are authored in the live admin, so this is a data
 * migration keyed by node key and safe to run twice).
 *
 * - "Who's there?" in the ghost process never set `njord_encountered`, so those players could never meet the JSC files.
 *   Players who already took that route get the flag now: they have the ghost's journal note, no encounter still open,
 *   and no flag.
 * - "Read INFRASTRUCTURE" in STATUS UPDATE 33 went to the HELP file instead of the INFRASTRUCTURE one.
 */
return new class extends Migration
{
    public function up(): void
    {
        $talk = DB::table('encounter_nodes')->where('node_key', 'ghost_process_talk')->value('id');

        if ($talk !== null && ! DB::table('encounter_outcomes')->where('node_id', $talk)->where('kind', 'flag')->where('ref', 'njord_encountered')->exists()) {
            DB::table('encounter_outcomes')->insert([
                'node_id' => $talk,
                'branch' => 'always',
                'position' => (int) DB::table('encounter_outcomes')->where('node_id', $talk)->max('position') + 1,
                'kind' => 'flag',
                'ref' => 'njord_encountered',
            ]);
        }

        $ghost = DB::table('encounters')->where('slug', 'ghost_process')->value('id');

        if ($ghost !== null) {
            DB::table('journal_notes')->where('slug', 'ghost_process')
                ->whereNotIn('character_id', DB::table('character_encounters')->where('encounter_id', $ghost)->select('character_id'))
                ->whereNotIn('character_id', DB::table('character_flags')->where('flag', 'njord_encountered')->select('character_id'))
                ->pluck('character_id')
                ->each(fn ($id) => DB::table('character_flags')->insertOrIgnore(['character_id' => $id, 'flag' => 'njord_encountered', 'set_at' => now()]));
        }

        $status = DB::table('encounter_nodes')->where('node_key', 'njord_jsc_files_status')->value('id');

        if ($status !== null) {
            DB::table('encounter_choices')->where('node_id', $status)->where('next_key', 'njord_jsc_files_help')
                ->update(['next_key' => 'njord_jsc_files_infra']);
        }
    }

    public function down(): void
    {
        // Content fix; nothing to undo.
    }
};
