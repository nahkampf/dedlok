<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a run is carrying while it happens.
 *
 * ICE ON THE RUN AND NOT READ OFF THE MACHINE, every turn. Two reasons and both
 * matter: a machine can be burned or relocated out from under a live run — the
 * case the whole address-not-id rule exists for — and, more simply, integrity
 * is being ground down. The catalogue row says what an ICE IS; these say what
 * this particular fight has left of it.
 *
 * `ice_id` is a foreign key here where `address` deliberately is not, and the
 * difference is what each points at. An address names a machine that may stop
 * existing; the ICE catalogue is authored content that is edited, not deleted,
 * and `nullOnDelete` covers the case where somebody retires a piece anyway —
 * the fight then has nothing fighting it, which is survivable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table): void {
            $table->boolean('detected')->default(false)->after('os_locked')
                ->comment('Has anything on the machine noticed them? Detection is what wakes the tracer and activates the ICE, and an undetected run walks past both.');

            $table->foreignId('ice_id')->nullable()->after('detected')
                ->comment('Which piece of ICE is fighting, if one woke up. NULL means nothing is: either the machine has none or nothing has noticed yet.')
                ->constrained('ice')->nullOnDelete();

            $table->unsignedSmallInteger('ice_integrity')->nullable()->after('ice_id')
                ->comment('What is LEFT of the ICE, ground down turn by turn. Carried here rather than read off the catalogue because the catalogue says what an ICE is and this says what this fight has done to it. NULL when nothing is fighting.');

            $table->unsignedTinyInteger('trace')->default(0)
                ->after('ice_integrity')
                ->comment('How far the trace has got, as a percentage. Advances per turn once a tracer is awake; a completed one puts heat on the character. Nothing advances it yet.');

            $table->unsignedSmallInteger('heat')->default(0)->after('trace')
                ->comment('Thermal load accumulated THIS RUN, in thermal units. Resets with the run because heat is a property of a run and not of a deck. Nothing accumulates it yet.');
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table): void {
            $table->dropForeign(['ice_id']);
            $table->dropColumn(['detected', 'ice_id', 'ice_integrity', 'trace', 'heat']);
        });
    }
};
