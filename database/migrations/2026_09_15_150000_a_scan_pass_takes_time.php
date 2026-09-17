<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reading a machine is something you start and come back to.
 *
 * A pass used to be instant, which made a scan a button somebody presses five
 * times in ten seconds. It now takes MINUTES, scaled by how hard the machine is
 * to read — about one for a grade 1 and about sixteen for a grade 9 — so the
 * budget of passes is a budget of an evening rather than of clicks, and the
 * difference between a Surveyor Mk I and an ORACLE is time somebody is
 * standing still in cyberspace.
 *
 * TWO TIMESTAMPS RATHER THAN ONE, because the screen wants a bar and a bar
 * needs both ends: `pass_started_at` is when the instrument was pointed at the
 * machine, `pass_ready_at` is when it will have an answer. Null on both means
 * nothing is running, which is the ordinary state.
 *
 * THE ROLL HAPPENS WHEN THE PASS MATURES, not when it is begun. Nothing is
 * decided in advance and stored waiting to be revealed: the character who
 * finishes the read is the one whose modifiers are asked, which is the only
 * arrangement where stopping the scanner halfway can ever be made to matter.
 * Settling is LAZY — the map screen resolves anything that has matured, the
 * way a training deposit catches up on read rather than needing a scheduler to
 * have been running.
 *
 * The pass is SPENT WHEN BEGUN. Walking away from a read that is half done
 * costs it, which is what stops the budget being dodged by starting five and
 * finishing whichever looks best.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_scans', function (Blueprint $table): void {
            $table->timestamp('pass_started_at')->nullable()->after('passes')
                ->comment('When the pass now running was begun, or NULL when none is. The other end of the bar the screen draws.');

            $table->timestamp('pass_ready_at')->nullable()->after('pass_started_at')
                ->comment('When the pass now running will have an answer. NULL when none is running; in the past means one is waiting to be settled.');
        });

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // A pass that is running has both ends or neither: half a pass is not
        // a state anything knows how to read.
        DB::statement(
            'ALTER TABLE `system_scans` ADD CONSTRAINT `chk_system_scans_pass_pair` CHECK ('
            .'(`pass_started_at` IS NULL AND `pass_ready_at` IS NULL)'
            .' OR (`pass_started_at` IS NOT NULL AND `pass_ready_at` IS NOT NULL))'
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `system_scans` DROP CONSTRAINT `chk_system_scans_pass_pair`');
        }

        Schema::table('system_scans', function (Blueprint $table): void {
            $table->dropColumn(['pass_started_at', 'pass_ready_at']);
        });
    }
};
