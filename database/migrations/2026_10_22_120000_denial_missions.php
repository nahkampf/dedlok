<?php

declare(strict_types=1);

use App\Missions\MissionAction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Denial (CYBERSPACE.md, *Denial*): the flood meter on a run, and the action CHECK widened (MySQL only). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table): void {
            $table->decimal('pressure', 6, 1)->default(0)->comment('A Denial run\'s flood meter, 0 to 100: each Flood adds the deck\'s bandwidth, the node pushes it back each turn, and full locks the node down. Meaningless on any other purpose.');
        });

        if (DB::getDriverName() !== 'sqlite') {
            $actions = "'".implode("','", MissionAction::values())."'";
            DB::statement('ALTER TABLE `missions` DROP CHECK `chk_missions_action`');
            DB::statement("ALTER TABLE `missions` ADD CONSTRAINT `chk_missions_action` CHECK (`action` IN ({$actions}))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::table('missions')->where('action', 'denial')->delete();
            DB::statement('ALTER TABLE `missions` DROP CHECK `chk_missions_action`');
            DB::statement("ALTER TABLE `missions` ADD CONSTRAINT `chk_missions_action` CHECK (`action` IN ('scan','burn','plant','agitate','extract','heist','transport','supply'))");
        }

        Schema::table('runs', function (Blueprint $table): void {
            $table->dropColumn('pressure');
        });
    }
};
