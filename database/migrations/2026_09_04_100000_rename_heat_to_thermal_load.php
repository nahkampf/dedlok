<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Heat is what the world knows about you. What a deck does is thermal load.
 *
 * A completed trace puts HEAT on a character — a persistent property of a
 * person, not a temperature inside a machine. One word for a wanted level and
 * for a figure on the deck screen is one word too few, and the two will sit on
 * adjacent screens. So every thermal name moves out of the way.
 *
 * | Was                              | Is                 |
 * | -------------------------------- | ------------------ |
 * | `deck_modules.heat`              | `thermal_load`     |
 * | `deck_modules.heat_level_warning`| `thermal_warning`  |
 * | `deck_modules.heat_level_limit`  | `thermal_limit`    |
 * | `chk_deck_modules_heat_levels`   | `..._thermal_levels` |
 *
 * `cooling` does not move: it was never called heat, and a radiator still
 * cools. Nor do `.slot--hot` and `.slot--doomed`, which live on the deck board
 * where hot can only mean temperature.
 *
 * Done NOW, before cyberspace is built, because a stat id is permanent the
 * moment a stored modifier references one — and the whole of CYBERSPACE.md is
 * about to reference these.
 *
 * The two CHECK constraints have to come off before the rename and go back on
 * after it: MySQL refuses to rename a column a constraint mentions. They are
 * MySQL-only in the first place — SQLite never had them, the same guard the
 * migrations that created them use.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mysql = DB::getDriverName() === 'mysql';

        if ($mysql) {
            DB::statement('ALTER TABLE `deck_modules` DROP CONSTRAINT `chk_deck_modules_thermal`');
            DB::statement('ALTER TABLE `deck_modules` DROP CONSTRAINT `chk_deck_modules_heat_levels`');
        }

        Schema::table('deck_modules', function (Blueprint $table): void {
            $table->renameColumn('heat', 'thermal_load');
            $table->renameColumn('heat_level_warning', 'thermal_warning');
            $table->renameColumn('heat_level_limit', 'thermal_limit');
        });

        if (! $mysql) {
            return;
        }

        // Unchanged in meaning: what takes thermal load away does not also
        // make it. The mirror of chk_deck_modules_supply.
        DB::statement(
            'ALTER TABLE `deck_modules` ADD CONSTRAINT `chk_deck_modules_thermal` '.
            'CHECK (`cooling` IS NULL OR `thermal_load` = 0)'
        );

        // Both thresholds or neither, and the warning at or below the limit.
        DB::statement(
            'ALTER TABLE `deck_modules` ADD CONSTRAINT `chk_deck_modules_thermal_levels` CHECK ('.
            '(`thermal_warning` IS NULL AND `thermal_limit` IS NULL) OR '.
            '(`thermal_warning` IS NOT NULL AND `thermal_limit` IS NOT NULL '.
            'AND `thermal_warning` <= `thermal_limit`))'
        );
    }

    public function down(): void
    {
        $mysql = DB::getDriverName() === 'mysql';

        if ($mysql) {
            DB::statement('ALTER TABLE `deck_modules` DROP CONSTRAINT `chk_deck_modules_thermal`');
            DB::statement('ALTER TABLE `deck_modules` DROP CONSTRAINT `chk_deck_modules_thermal_levels`');
        }

        Schema::table('deck_modules', function (Blueprint $table): void {
            $table->renameColumn('thermal_load', 'heat');
            $table->renameColumn('thermal_warning', 'heat_level_warning');
            $table->renameColumn('thermal_limit', 'heat_level_limit');
        });

        if (! $mysql) {
            return;
        }

        DB::statement('ALTER TABLE `deck_modules` ADD CONSTRAINT `chk_deck_modules_thermal` CHECK (`cooling` IS NULL OR `heat` = 0)');
        DB::statement(
            'ALTER TABLE `deck_modules` ADD CONSTRAINT `chk_deck_modules_heat_levels` CHECK ('.
            '(`heat_level_warning` IS NULL AND `heat_level_limit` IS NULL) OR '.
            '(`heat_level_warning` IS NOT NULL AND `heat_level_limit` IS NOT NULL '.
            'AND `heat_level_warning` <= `heat_level_limit`))'
        );
    }
};
