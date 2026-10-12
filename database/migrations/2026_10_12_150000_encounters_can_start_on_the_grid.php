<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * An encounter may start on the grid: `starts_on` gains `grid_step`, an arrival by a move in cyberspace.
 *
 * The realm is the door's (`EncounterStart::isGrid()`), so there is no second column to agree with it. The only
 * schema change is the CHECK that lists the doors, which is on a plain column and so legal on MySQL. SQLite never
 * had it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE encounters DROP CHECK chk_encounters_starts_on');
        DB::statement("ALTER TABLE encounters ADD CONSTRAINT chk_encounters_starts_on CHECK (starts_on IN ('travel','explore','place','grid_step'))");
        DB::statement("ALTER TABLE encounters MODIFY starts_on VARCHAR(16) NOT NULL COMMENT 'What can open it: travel (a pass of a trip matured), explore (a pass of exploring matured), place (a button on a location page) or grid_step (an arrival by a move in cyberspace). The realm follows the door.'");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::table('encounters')->where('starts_on', 'grid_step')->delete();
        DB::statement('ALTER TABLE encounters DROP CHECK chk_encounters_starts_on');
        DB::statement("ALTER TABLE encounters ADD CONSTRAINT chk_encounters_starts_on CHECK (starts_on IN ('travel','explore','place'))");
    }
};
