<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where a character is standing in cyberspace, and therefore whether they are
 * in it at all.
 *
 * TWO COLUMNS RATHER THAN A SESSION TABLE, for the reason
 * `characters.equipped_deck_id` is a column: a character is in at most one
 * place at a time, and one column holding one value is a rule nobody has to
 * enforce. A session row would let two exist and would need a writer to
 * promise they never do.
 *
 * NULL IS THE ORDINARY STATE. A character not in cyberspace has no position,
 * and jacked-in is exactly "has a position" rather than a third column free to
 * disagree with these two. The pair is null together or set together, which is
 * what the CHECK below says in SQL.
 *
 * A POSITION IS NOT RESUMED. Jacking out clears it and every jack-in arrives
 * at the portal, because the design's answer to "how do I get back to the
 * frontier" is a bookmark rather than a saved coordinate — see docs/CYBERSPACE.md,
 * *Discovery*. That is also why there is no `jacked_in_at` here: nothing reads
 * one yet, and the trace is the feature that will want to know when a session
 * began. It can add the column it needs when it needs it.
 *
 * They are deliberately NOT in Character's #[Fillable], like `is_admin` and
 * `equipped_deck_id`: App\Cyberspace\Navigator is the only writer, and a
 * position must never arrive from a request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            // Same width as `systems`: unsignedSmallInteger holds 65 535,
            // which covers a 2048 grid with room left over.
            $table->unsignedSmallInteger('grid_x')->nullable()->after('equipped_deck_id');
            $table->unsignedSmallInteger('grid_y')->nullable()->after('grid_x');
        });

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Half a position is not a place. Either both coordinates are there or
        // neither is, so "am I in cyberspace" has one answer however it is
        // asked.
        DB::statement(
            'ALTER TABLE `characters` ADD CONSTRAINT `chk_characters_grid_position` CHECK ('.
            '(`grid_x` IS NULL AND `grid_y` IS NULL) OR '.
            '(`grid_x` IS NOT NULL AND `grid_y` IS NOT NULL))'
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `characters` DROP CONSTRAINT `chk_characters_grid_position`');
        }

        Schema::table('characters', function (Blueprint $table): void {
            $table->dropColumn(['grid_x', 'grid_y']);
        });
    }
};
