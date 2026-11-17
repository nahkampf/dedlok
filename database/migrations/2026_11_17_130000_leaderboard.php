<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The leaderboard's snapshot (docs/LEADERBOARD.md): written hourly by `leaderboard:snapshot`, only read by the page. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leaderboard_entries', function (Blueprint $table): void {
            $table->comment('One ranked character on one board, as of the last snapshot. Rewritten whole, board by board, by App\\Leaderboard\\Snapshot.');
            $table->id()->comment('Primary key.');
            $table->string('board', 24)->comment('App\\Leaderboard\\Board.');
            $table->foreignId('character_id')->comment('Who is ranked.')->constrained()->cascadeOnDelete();
            $table->bigInteger('value')->comment('What the board counts. Only values above 0 are ranked.');
            $table->unsignedInteger('rank')->comment('Competition rank: equal values share one (1, 2, 2, 4).');
            $table->unsignedInteger('position')->comment('Order on the board: rank, then handle alphabetically. 1 is first.');
            $table->timestamp('written_at')->comment('When the snapshot was taken.');
            $table->unique(['board', 'character_id']);
            $table->index(['board', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leaderboard_entries');
    }
};
