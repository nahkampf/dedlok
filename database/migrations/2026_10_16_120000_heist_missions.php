<?php

declare(strict_types=1);

use App\Missions\MissionAction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Heists: the creds a job's runner has raided and not yet handed in; the action CHECK widened (MySQL only). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('missions', function (Blueprint $table): void {
            $table->unsignedInteger('proceeds')->default(0)->after('penalty')
                ->comment('Heist only: creds raided off the job\'s systems and held for the desk instead of the wallet. Paid back in part at hand-in; lost when the job fails. Zero for every other kind.');
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
        Schema::table('missions', function (Blueprint $table): void {
            $table->dropColumn('proceeds');
        });
    }
};
