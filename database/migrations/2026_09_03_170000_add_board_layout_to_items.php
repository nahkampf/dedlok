<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a deck's sockets sit when you look at the thing.
 *
 * A picture, not a fact about a socket — which is why it is not two columns on
 * `deck_slots`. A deck with four QDRAM sockets draws ONE box holding four
 * slots, so the coordinate belongs to the (kind, variant) pair within this
 * deck, and there is exactly one of those per pair. Putting x and y on the
 * rows would store the same coordinate four times and let the four disagree.
 *
 * A document rather than a table for the same reason it is not queried:
 * nothing will ever ask which decks have a NIC above the midline. It is read
 * whole to draw one screen and written whole by one editor, which is the shape
 * `deck_slots` is already replaced in.
 *
 * Named for what it holds. `metadata` is a name that invites the next
 * unrelated thing in beside it, and then nothing can validate either of them.
 *
 * NULL is not missing data. It means "no one has arranged this deck", and
 * App\Decks\BoardLayout draws the automatic ring instead — so every deck that
 * already exists renders on the day this lands, without an author touching it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            // json rather than text: MySQL 8.0 validates the shape on write,
            // which is the one thing about this the database CAN say.
            $table->json('board_layout')->nullable()->after('hardware');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->dropColumn('board_layout');
        });
    }
};
