<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How far along the routing chain the trace has got.
 *
 * A TRACE WALKS THE CHAIN, one hop at a time. Each turn is a single attempt at
 * the NEXT system in the route: pass it and the trace moves up and the turn
 * ends; fail and it stays where it is and the turn ends anyway. A hop once
 * passed is behind it, and it never has to pass that one again.
 *
 * That is the difference between this column existing and not. Without it the
 * only way to ask "is the trace through the chain" is to roll every hop every
 * turn — which makes a long chain not slow but impassable, because a ten-hop
 * chain would need ten simultaneous successes for ever rather than ten
 * successes eventually.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table): void {
            $table->unsignedTinyInteger('trace_hop')->default(0)->after('trace')
                ->comment('How many systems of the routing chain the trace has already passed. It attempts the NEXT one each turn and a hop once passed is behind it — which is why this is a position and not a count of successes.');
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table): void {
            $table->dropColumn('trace_hop');
        });
    }
};
