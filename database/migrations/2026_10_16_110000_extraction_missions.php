<?php

declare(strict_types=1);

use App\Missions\MissionAction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Extraction jobs: a file seeded on a system for one runner, and the action CHECK widened (MySQL only). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_contents', function (Blueprint $table): void {
            $table->unsignedBigInteger('for_character_id')->nullable()->after('deposited_by')
                ->comment('NULL: anyone raiding the node may take it. Set: a job file only that character can see and pull.');
            $table->unsignedBigInteger('mission_id')->nullable()->after('for_character_id')
                ->comment('The exchange job this file was seeded for, cleared with the job. A plain id, never a foreign key. NULL is ordinary stock.');
            $table->index('mission_id');
        });

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $actions = "'".implode("','", MissionAction::values())."'";

        DB::statement('ALTER TABLE `missions` DROP CHECK `chk_missions_action`');
        DB::statement("ALTER TABLE `missions` ADD CONSTRAINT `chk_missions_action` CHECK (`action` IN ({$actions}))");
    }

    public function down(): void
    {
        Schema::table('system_contents', function (Blueprint $table): void {
            $table->dropIndex(['mission_id']);
            $table->dropColumn(['for_character_id', 'mission_id']);
        });
    }
};
