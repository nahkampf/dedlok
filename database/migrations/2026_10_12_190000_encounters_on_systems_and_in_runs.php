<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two more doors for an encounter in cyberspace (ENCOUNTERS_GRID.md): arriving at a SYSTEM, and between turns of a RUN.
 *
 * - `encounters` gains the filters those doors read: the system's type, a grade range, its owner (a plain id: an
 *   encounter's filter is a remembered preference, and an entity that goes must not take the encounter with it),
 *   and a run's purpose.
 * - `encounter_nodes.run_end` is what an ending of a run encounter does to the run: kill_run or continue_run.
 * - `character_encounters.run_id` says which run it opened in, so closing knows which one to end or leave. A plain
 *   column and never a foreign key: a run is remembered, not owned.
 * - The CHECK on `starts_on` lists the doors, so it is widened. It is on a plain column, so legal on MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('encounters', function (Blueprint $table): void {
            $table->string('system_type', 24)->nullable()->after('location_id')
                ->comment('For the system door: only a system of this type (SystemType). NULL is any.');
            $table->unsignedTinyInteger('grade_min')->nullable()->after('system_type')
                ->comment('For the system and run doors: only a system of at least this grade. NULL is no floor.');
            $table->unsignedTinyInteger('grade_max')->nullable()->after('grade_min')
                ->comment('For the system and run doors: only a system of at most this grade. NULL is no ceiling.');
            $table->unsignedBigInteger('owner_entity_id')->nullable()->after('grade_max')
                ->comment('For the system door: only a system this entity owns. NULL is any. Not a foreign key.');
            $table->string('run_purpose', 16)->nullable()->after('owner_entity_id')
                ->comment('For the run door: only a run of this purpose (RunPurpose). NULL is any.');
        });

        Schema::table('encounter_nodes', function (Blueprint $table): void {
            $table->string('run_end', 16)->nullable()->after('ends')
                ->comment('On an ending of a run encounter: kill_run (the run ends) or continue_run (it carries on). NULL elsewhere; required there (held by the request).');
        });

        Schema::table('character_encounters', function (Blueprint $table): void {
            $table->unsignedBigInteger('run_id')->nullable()->after('encounter_id')
                ->comment('The run it opened in, for a run encounter. NULL otherwise. Not a foreign key: the run is remembered, not owned.');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE encounters DROP CHECK chk_encounters_starts_on');
            DB::statement("ALTER TABLE encounters ADD CONSTRAINT chk_encounters_starts_on CHECK (starts_on IN ('travel','explore','place','grid_step','system','run'))");
            DB::statement('ALTER TABLE encounters ADD CONSTRAINT chk_encounters_grades CHECK (grade_min IS NULL OR grade_max IS NULL OR grade_min <= grade_max)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::table('encounters')->whereIn('starts_on', ['system', 'run'])->delete();
            DB::statement('ALTER TABLE encounters DROP CHECK chk_encounters_grades');
            DB::statement('ALTER TABLE encounters DROP CHECK chk_encounters_starts_on');
            DB::statement("ALTER TABLE encounters ADD CONSTRAINT chk_encounters_starts_on CHECK (starts_on IN ('travel','explore','place','grid_step'))");
        }

        Schema::table('character_encounters', fn (Blueprint $t) => $t->dropColumn('run_id'));
        Schema::table('encounter_nodes', fn (Blueprint $t) => $t->dropColumn('run_end'));
        Schema::table('encounters', fn (Blueprint $t) => $t->dropColumn(['system_type', 'grade_min', 'grade_max', 'owner_entity_id', 'run_purpose']));
    }
};
