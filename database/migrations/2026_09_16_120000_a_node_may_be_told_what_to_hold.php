<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Most machines hold whatever the world gives them. Some are told.
 *
 * The ordinary node draws its items at random, and that is what nearly every
 * machine on the grid will always do. What this column is for is the ones that
 * cannot: a mission system has to be holding the thing the mission is about,
 * and "the world happened to put it there" is not a sentence a quest can be
 * built on.
 *
 * NULL IS THE ORDINARY STATE and means *roll for it*, which is the same shape
 * `items.board_layout` and `systems.name` already take — the derived answer is
 * the common one and the column is the override. A grid of three thousand
 * machines stores three thousand nulls and nothing has to be authored for the
 * world to work.
 *
 * TWO SHAPES, because the design named two:
 *
 *   {"fixed":  [{"item_id": 12, "quantity": 1}]}      always exactly these
 *   {"any_of": {"from": [12, 13, 14], "count": 2}}    this many, drawn from a set
 *
 * They combine, and either may stand alone. A rule REPLACES the random draw
 * rather than adding to it — a machine told what to hold is holding that —
 * unless it says `"plus_random": true`, which is for a node that should look
 * ordinary while still carrying the one thing that matters.
 *
 * The rule governs ITEMS only. Creds and files are still rolled, because a
 * mission node that held no money would announce itself as a mission node; the
 * file a quest is actually about will be written straight in as a row when
 * missions exist, rather than being described here. See docs/TODO.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('systems', function (Blueprint $table): void {
            $table->json('stock_rule')->nullable()->after('raids')
                ->comment('What this machine must be holding, as {"fixed": [...], "any_of": {...}}. NULL is the ordinary state and means the restock rolls for it. Items only: creds and files are always rolled.');
        });
    }

    public function down(): void
    {
        Schema::table('systems', function (Blueprint $table): void {
            $table->dropColumn('stock_rule');
        });
    }
};
