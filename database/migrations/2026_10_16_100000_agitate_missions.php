<?php

declare(strict_types=1);

use App\Missions\MissionAction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** A fifth kind of job: wake a system's ICE and get out so it locks down. Widens the action CHECK (MySQL only). */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $actions = "'".implode("','", MissionAction::values())."'";

        DB::statement('ALTER TABLE `missions` DROP CHECK `chk_missions_action`');
        DB::statement("ALTER TABLE `missions` ADD CONSTRAINT `chk_missions_action` CHECK (`action` IN ({$actions}))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE `missions` DROP CHECK `chk_missions_action`');
        DB::statement("ALTER TABLE `missions` ADD CONSTRAINT `chk_missions_action` CHECK (`action` IN ('scan','burn','plant','supply'))");
    }
};
