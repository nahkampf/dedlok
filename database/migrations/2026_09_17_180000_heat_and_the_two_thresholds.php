<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a completed trace leaves behind, and when to get out before it does.
 *
 * ## Two different things were both called heat
 *
 * `runs.heat` was thermal load — the deck cooking, in thermal units, reset with
 * the run — and CYBERSPACE.md uses the same word for what a completed trace
 * puts on a CHARACTER: persistent, theirs, and what the world knows about them.
 * One word for a per-run number and a permanent one is a bug waiting for
 * somebody to read the wrong column at three in the morning, so the run's
 * becomes `thermal_load`, which is what the design calls it everywhere else
 * anyway, and `heat` is left meaning the thing that follows you home.
 *
 * ## The thresholds are a play style, not a per-run decision
 *
 * Which is why they live on the character and persist. Every character is born
 * with them so that somebody who has never found the sliders is still carried
 * out of a run they were losing — the whole reason they ACT rather than warn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table): void {
            $table->renameColumn('heat', 'thermal_load');
        });

        Schema::table('characters', function (Blueprint $table): void {
            $table->unsignedInteger('heat')->default(0)->after('creds')
                ->comment('What the world knows about this character, from traces that completed. PERSISTENT and theirs — not the run\'s thermal load, which is a different quantity that used to share the word. One per completed trace for now; what heat DOES is not designed.');

            $table->unsignedTinyInteger('egress_trace_at')->default(70)
                ->after('current_run_id')
                ->comment('Pull out when the trace passes this percentage. A play style rather than a per-run choice, which is why it lives here and persists. It ACTS rather than warns: a threshold that only warned would leave an unresponsive player in a run that never resolves.');

            $table->unsignedTinyInteger('egress_si_at')->default(30)
                ->after('egress_trace_at')
                ->comment('Pull out when Synaptic Integrity falls below this percentage of maximum. Every character is born with it, so somebody who never found the sliders is still carried out of a run they were losing.');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->dropColumn(['heat', 'egress_trace_at', 'egress_si_at']);
        });

        Schema::table('runs', function (Blueprint $table): void {
            $table->renameColumn('thermal_load', 'heat');
        });
    }
};
