<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A deck has no thermal rating, and heat does not happen TO it.
 *
 * `items.heat_capacity` lasted one migration. It made the chassis a second
 * thing heat was measured against, and there is only one: the parts. A deck is
 * where heat is produced and where it is taken away, and it is indifferent to
 * the number that comes out — what that number does is done to the modules,
 * each against its own two thresholds.
 *
 * That is a better design than the one it replaces, and not only a smaller
 * one. With a chassis cap, a deck could report itself healthy while cooking
 * the part in it that mattered, or report itself in trouble while every part
 * was comfortable — two verdicts about one thing, free to disagree. Now there
 * is one question, asked once per module: is THIS part past its warning, and
 * is it past its limit.
 *
 * Added in 2026_09_03_200000 and removed here rather than edited out of it,
 * because that migration has run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->dropColumn('heat_capacity');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->unsignedInteger('heat_capacity')->default(0)->after('board_layout');
        });
    }
};
