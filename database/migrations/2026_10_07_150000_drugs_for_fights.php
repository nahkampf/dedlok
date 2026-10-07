<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drugs that are used only in a fight, and tick on TURNS instead of minutes.
 *
 * A setting on the drug rather than a second kind of item: the same families, books, tolerance and overdose, and the
 * same editor panel. A fight-only drug's length is `duration_turns` (its `duration_minutes` is 0 and unread), and its
 * dose row counts down `turns_left` once a round instead of waiting for `expires_at`, which becomes a long backstop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drugs', function (Blueprint $table): void {
            $table->boolean('fight_only')->default(false)->after('family')
                ->comment('1 = can only be taken inside a fight, from the belt, as that turn\'s action. Its length is duration_turns and duration_minutes is not read.');
            $table->unsignedTinyInteger('duration_turns')->nullable()->after('duration_minutes')
                ->comment('How many rounds of a fight one dose lasts. Set exactly when fight_only is 1; NULL otherwise.');
            $table->smallInteger('turn_hitpoints')->default(0)->after('duration_turns')
                ->comment('Hit points restored (positive, scaled by the dose\'s potency) or lost (negative, not scaled and not mitigable) at the start of each round while a fight-only dose lasts. 0 = none.');
        });

        Schema::table('character_doses', function (Blueprint $table): void {
            $table->unsignedTinyInteger('turns_left')->nullable()->after('expires_at')
                ->comment('Rounds of a fight this dose has left. NULL = an ordinary dose that ends at expires_at. A fight-only dose is in effect while this is above 0 and is deleted when the fight ends; expires_at is only a backstop for a fight that never finished.');
        });
    }

    public function down(): void
    {
        Schema::table('character_doses', function (Blueprint $table): void {
            $table->dropColumn('turns_left');
        });

        Schema::table('drugs', function (Blueprint $table): void {
            $table->dropColumn(['fight_only', 'duration_turns', 'turn_hitpoints']);
        });
    }
};
