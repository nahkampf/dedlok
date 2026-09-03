<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How much heat a module can stand before it is in trouble, and before it is
 * gone.
 *
 * These are thresholds on the DECK'S heat, not on the module's own output. A
 * module says "I start to suffer once the deck is running at 150, and I do not
 * survive 220" — so the same stick of RAM is safe in a cold deck and dying in
 * a hot one, which is the whole point. It is what turns heat from a number
 * into a decision: fit a radiator, buy the part that tolerates more, or run
 * hot and accept the risk.
 *
 * NULLABLE, AND NULLABLE TOGETHER. Null means no known limit — a module that
 * does not care how hot the deck gets. That is a real answer and it is the
 * honest one for every module already in the catalogue: inventing a tolerance
 * for each of them now would be a number to migrate away from once the design
 * has one. The CHECK is the same shape as `chk_items_capacity_pair`: both set
 * or neither, and the warning cannot be above the limit.
 *
 * Nothing is destroyed yet. The limit is a hard 100% and the warning is where
 * a risk calculation would begin, but running a deck is not built, so what
 * exists here is the assessment and the way it is shown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deck_modules', function (Blueprint $table): void {
            // Where trouble starts: a pulsing socket, and where the risk of
            // destruction begins once there is a risk calculation.
            $table->unsignedInteger('heat_level_warning')->nullable()->after('cooling');

            // And where it ends. A hard limit rather than the top of a curve:
            // at this much deck heat the module is gone, whatever any earlier
            // roll said.
            $table->unsignedInteger('heat_level_limit')->nullable()->after('heat_level_warning');
        });

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(
            'ALTER TABLE `deck_modules` ADD CONSTRAINT `chk_deck_modules_heat_levels` CHECK ('.
            '(`heat_level_warning` IS NULL AND `heat_level_limit` IS NULL) OR '.
            '(`heat_level_warning` IS NOT NULL AND `heat_level_limit` IS NOT NULL '.
            'AND `heat_level_warning` <= `heat_level_limit`))'
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `deck_modules` DROP CONSTRAINT `chk_deck_modules_heat_levels`');
        }

        Schema::table('deck_modules', function (Blueprint $table): void {
            $table->dropColumn(['heat_level_warning', 'heat_level_limit']);
        });
    }
};
