<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which deck is live. One per character, and NULL is a real state.
 *
 * A column on `characters` rather than a flag on `inventory_items`, which
 * MECHANICS asked for and gave the reason: one column can only hold one value,
 * so "only one deck at a time" stops being a rule anybody has to enforce. The
 * flag version would need a partial unique index and MySQL 8.0 has none.
 *
 * NULL means jacked out. Not missing data — the ordinary state of a character
 * who has not picked a deck, and the state anyone can go back to.
 *
 * `nullOnDelete` rather than a cascade: destroying the deck object jacks you
 * out, it does not delete you. It is the same shape as the fitting rows the
 * deck also loses — see 2026_09_03_180000_create_deck_fittings.
 *
 * Two things the database cannot say about this column, both of which live in
 * App\Decks\DeckEquipper because no CHECK can reach across a table: that the
 * row belongs to this character, and that it is a cyberdeck.
 *
 * Nothing reads it yet. Equipping decides which deck's numbers would resolve,
 * and no number resolves — see MECHANICS, "One deck is equipped; the rest are
 * luggage". Do not describe an equipped deck as doing anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->foreignId('equipped_deck_id')->nullable()->after('creds')
                ->constrained('inventory_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('equipped_deck_id');
        });
    }
};
