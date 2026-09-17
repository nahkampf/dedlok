<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Being on the floor, and what put you there.
 *
 * Reaching 0 Synaptic Integrity or 0 hit points has had no consequence at all
 * until now — `takeDamage()` would take somebody to zero and say so in a
 * comment. This is the floor under every defeat: whatever the program that beat
 * you carried, and whether it carried anything, the wait is what losing always
 * costs.
 *
 * TWO COLUMNS, AND THE CAUSE IS JSON ON PURPOSE. What put somebody down is a
 * run on a named machine, a named piece of ICE, an amount of damage and a list
 * of defeat tags — and none of that exists yet. A set of columns modelled now
 * would be modelled against a simulator nobody has written; a blob lets the
 * cause say what it knows and lets the screen render what it is given. When the
 * shape settles it can become columns, and nothing else has to change.
 *
 * It is NOT a foreign key to `runs`, for the same reason the run is not one to
 * `systems`: being incapacitated outlives the thing that did it, and it does
 * not always come from a run at all — a readiness loss can take the last point.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->timestamp('waiting_until')->nullable()->after('current_run_id')
                ->comment('When this character gets back up, or NULL. Non-null is what "on the floor" means — one column, so nothing can disagree about whether somebody is down.');

            $table->json('waiting_cause')->nullable()->after('waiting_until')
                ->comment('What put them there, as the screen should tell it: the machine, the ICE, the damage, the defeat tags. JSON because none of that is built yet and columns modelled against an unwritten simulator would be modelled wrong.');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->dropColumn(['waiting_until', 'waiting_cause']);
        });
    }
};
