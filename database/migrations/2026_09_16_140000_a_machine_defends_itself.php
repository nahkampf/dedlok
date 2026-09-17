<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a machine defends itself with, and what a scan can find out about it.
 *
 * Two of the three countermeasures CYBERSPACE.md names: the intrusion detection
 * and the tracer. ICE is the third and arrives on its own, because its numbers
 * are combat numbers and there is no turn loop to calibrate them against yet.
 *
 * ZERO MEANS THERE IS NONE, and these columns are NOT NULL for it. Three
 * reasons, and the first is the one that matters: "has an IDS" is then
 * `ids_detection > 0` in every caller rather than two different questions
 * depending on which table is being asked. The second is that a row inserted by
 * hand defaults to carrying nothing, the same principle `items.seed_quantity`
 * and `archetypes.playable` take - nothing reaches a player by being forgotten.
 * The third is that it leaves NULL free to mean exactly one thing on the scan
 * side, where it has to distinguish "I looked and there is none" from "I have
 * not looked".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('systems', function (Blueprint $table): void {
            $table->unsignedTinyInteger('ids_detection')->default(0)->after('os_skill_id')
                ->comment('How hard this machine\'s intrusion detection is to get past, as a difficulty for App\Checks\CheckRoller. 0 means it has none. Rolled once at creation from type and grade and stored, never derived on read - which is what lets a mission node carry a grade 9 IDS on a grade 2 box.');

            $table->unsignedTinyInteger('tracer_detection')->default(0)->after('ids_detection')
                ->comment('What the tracer itself notices, as a difficulty. Much lower than an IDS: everything hostile can notice you, not just the detection. 0 means no tracer, and it is 0 exactly when tracer_rate is.');

            $table->unsignedTinyInteger('tracer_rate')->default(0)->after('tracer_detection')
                ->comment('How fast the tracer traces, as per cent of a completed trace per turn. Nothing reads it: the trace advances per turn and there is no turn loop yet. 0 exactly when tracer_detection is.');
        });

        Schema::table('system_scans', function (Blueprint $table): void {
            $table->unsignedTinyInteger('ids_detection')->nullable()->after('os_skill_id')
                ->comment('What the scan found out about the intrusion detection. NULL is NOT FOUND OUT; 0 is found out and there is none. Those are different states here in a way they are not on systems, which is why this column is nullable and that one is not.');

            $table->unsignedTinyInteger('tracer_detection')->nullable()->after('ids_detection')
                ->comment('What the scan found out the tracer notices. NULL is not found out, 0 is found out and there is no tracer.');

            $table->unsignedTinyInteger('tracer_rate')->nullable()->after('tracer_detection')
                ->comment('How fast the scan found out the tracer traces. Answered with tracer_detection - one fact, one roll - so the two are NULL together and filled together.');
        });

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // A tracer is a thing or it is not. Both columns describe the same
        // machine, so a rate with nothing to notice you - or a watcher that
        // traces nowhere - is a half-written row rather than a quiet tracer.
        // The same "null exactly together" discipline inventory_items uses for
        // running_on_deck_id and started_at.
        DB::statement('ALTER TABLE `systems` ADD CONSTRAINT `chk_systems_tracer` CHECK ((`tracer_detection` = 0) = (`tracer_rate` = 0))');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `systems` DROP CONSTRAINT `chk_systems_tracer`');
        }

        Schema::table('systems', function (Blueprint $table): void {
            $table->dropColumn(['ids_detection', 'tracer_detection', 'tracer_rate']);
        });

        Schema::table('system_scans', function (Blueprint $table): void {
            $table->dropColumn(['ids_detection', 'tracer_detection', 'tracer_rate']);
        });
    }
};
