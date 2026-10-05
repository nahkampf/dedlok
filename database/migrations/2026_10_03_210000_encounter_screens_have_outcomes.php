<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An outcome may belong to a SCREEN: it happens when the character arrives there.
 *
 * Until now an outcome hung off a button, so a screen's own text ("a fist to your face") could
 * not cost anything. `node_id` is the other owner, and `choice_id` becomes nullable: exactly one
 * of the two is set. That rule cannot be a CHECK — both are cascading foreign keys, and MySQL
 * refuses a CHECK on such a column (error 3823) — so `EncounterRequest` and
 * `EncounterEditor` hold it, as they hold "an item outcome has an item".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('encounter_outcomes', function (Blueprint $table): void {
            $table->foreignId('node_id')->nullable()->after('choice_id')
                ->comment('The screen that causes it, applied on arrival, when choice_id is NULL. Exactly one of the two is set (held by the request, not a CHECK: both cascade).')
                ->constrained('encounter_nodes')->cascadeOnDelete();
        });

        Schema::table('encounter_outcomes', function (Blueprint $table): void {
            $table->unsignedBigInteger('choice_id')->nullable()
                ->comment('The choice that causes it. NULL when the outcome belongs to a screen (node_id) instead.')
                ->change();
        });
    }

    public function down(): void
    {
        // A screen's outcomes have nowhere to live in the old shape.
        DB::table('encounter_outcomes')->whereNull('choice_id')->delete();

        Schema::table('encounter_outcomes', function (Blueprint $table): void {
            $table->unsignedBigInteger('choice_id')->nullable(false)
                ->comment('The choice that causes it.')
                ->change();
            $table->dropForeign(['node_id']);
            $table->dropColumn('node_id');
        });
    }
};
