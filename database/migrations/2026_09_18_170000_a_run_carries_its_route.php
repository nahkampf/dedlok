<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The route a run is taking, snapshotted when it begins.
 *
 * DECLARED PER RUN AND NEVER HELD STANDING, which is docs/CYBERSPACE.md's own rule
 * — so the chain is a column on `runs` rather than a property of the
 * character. A backdoor installed after a run began is not in that run's
 * route, and one lost during it drops out.
 *
 * SYSTEM IDS AND NOT GRADES, because a grade copied at the start would be a
 * second answer to a question the machine can already answer, and because the
 * degradation rule needs to know whether each hop is STILL there. It is also
 * why the stored list is never rewritten: the positions in it are what
 * `runs.trace_hop` indexes, so a hop that goes dark becomes a gap the trace
 * walks straight past rather than a shift in everything behind it.
 *
 * NULL is the ordinary state — no routing backdoors, or none allowed by
 * `routing_hops` — and means the same as an empty list: the trace comes on
 * unopposed from the first turn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table): void {
            $table->json('chain')->nullable()->after('trace_hop')
                ->comment('The system ids this run routes through, in the order a trace meets them, snapshotted when it began. NULL or [] is no routing at all. Never rewritten: trace_hop indexes it, and a hop that has since gone is a gap rather than a shift.');
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table): void {
            $table->dropColumn('chain');
        });
    }
};
