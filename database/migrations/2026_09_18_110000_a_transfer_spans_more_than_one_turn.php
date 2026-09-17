<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A file costs turns on the way out, and a run has to remember it mid-pull.
 *
 * CYBERSPACE.md prices a file in QB, cut back down by the deck's bandwidth —
 * "a few turns, scaling with the size of the data." `App\Cyberspace\Extractor`
 * already took every file in the one turn extraction was attempted; this is
 * the column that lets it take longer than one.
 *
 * NULL means no transfer is running, the ordinary state for every turn that
 * is not one. A non-null value is what QB are still owed — decremented by the
 * deck's bandwidth each turn `RunAction::Extract` is chosen again, the same
 * way `ice_integrity` counts a fight down rather than up. It lives on the RUN
 * and not on a `system_contents` row, because it is a fact about how much of
 * THIS attempt has landed, not about the file: two characters pulling from the
 * same node are two independent countdowns.
 *
 * Set to null again the instant a transfer completes or a run ends, so a run
 * can never carry a stale countdown into a machine it is no longer at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table): void {
            $table->unsignedInteger('extracting_qb')->nullable()->after('extracted')
                ->comment('QB still owed on a file transfer in progress. Null when none is running - the ordinary state. Counts down by the deck\'s bandwidth each turn Extract is chosen while it is set, and clears the moment the transfer finishes.');
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table): void {
            $table->dropColumn('extracting_qb');
        });
    }
};
