<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Declining a job keeps the row as `declined`, and the state CHECK never learned the word: every decline was a 500 on MySQL.
 * SQLite tests have no such CHECK, which is why nothing caught it. MySQL only.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->states(['offered', 'declined', 'accepted', 'completed', 'failed', 'voided']);
    }

    public function down(): void
    {
        DB::table('missions')->where('state', 'declined')->delete();

        $this->states(['offered', 'accepted', 'completed', 'failed', 'voided']);
    }

    /** @param list<string> $states */
    private function states(array $states): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $list = "'".implode("','", $states)."'";

        DB::statement('ALTER TABLE `missions` DROP CHECK `chk_missions_state`');
        DB::statement("ALTER TABLE `missions` ADD CONSTRAINT `chk_missions_state` CHECK (`state` IN ({$list}))");
    }
};
