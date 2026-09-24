<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The table of offers `items.on_market` was never going to be.
 *
 * `on_market` is the SEEDER's question — whether a shop stocks a KIND of thing
 * — and CLAUDE.md has said since the shelf was built that player listings
 * would be a table of offers instead. This is that table. The two markets
 * share a screen and nothing else: the shelf sells catalogue entries that are
 * conjured on payment, and this sells one particular row out of one
 * particular stash.
 *
 * ## An offer points at an INVENTORY ROW, not at an item
 *
 * Which is the whole difference. A scan file is the reason the distinction
 * finally had to be real: every one of them is the same catalogue entry and a
 * different machine, so "one Scan File, 400 creds" is not an offer anybody
 * could act on. `inventory_item_id` is unique — a row is listed once or not at
 * all — and it cascades, because an offer for a row that has been destroyed is
 * not an offer.
 *
 * ## Listed is a fifth way to be elsewhere
 *
 * `InventoryItem::scopeLoose()` already excludes four: inside a container,
 * fitted into a socket, running, installed in a body. A listed row joins them
 * rather than gaining a column of its own — the offer row IS the fact, and a
 * `listed_at` beside it would be a second copy to keep in step.
 *
 * ## The price is PER UNIT, and the buyer says how many
 *
 * A listed row is a pile somebody is selling from, not a lot. Three of twenty
 * leaves seventeen on offer at the same figure, which is why the price is per
 * unit and the quantity lives on the inventory row rather than here — the row
 * IS what is left. An unstackable row is a pile of one and the same arithmetic
 * gives the same answer.
 *
 * `market_purchases.unit_price` has recorded what a thing cost that day since
 * the shelf was built; this is the same word for the same idea.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_offers', function (Blueprint $table): void {
            $table->comment('One player selling one row out of their stash to another. The shelf (market_stock) is the shop; this is everybody else.');

            $table->id()->comment('Surrogate key. Nothing outside this table references it.');

            $table->foreignId('seller_character_id')
                ->comment('Who is selling. Kept beside the row rather than read through it, because the row changes hands at the moment of sale and the offer is gone by then.')
                ->constrained('characters')
                ->cascadeOnDelete();

            $table->foreignId('inventory_item_id')
                ->comment('The particular row on offer. Unique: a row is listed once or not at all. Cascades, because an offer for a destroyed row is not an offer.')
                ->constrained('inventory_items')
                ->cascadeOnDelete();

            $table->unsignedInteger('unit_price')
                ->comment('What the seller wants per unit, in creds. The quantity on offer is the inventory row\'s own - buying part of a stack leaves the rest listed at this same figure. Zero is allowed and means a gift.');

            // Spelled out rather than $table->timestamps(), which returns void
            // and so cannot be commented inline — see CLAUDE.md. `created_at`
            // is when it went up, which is the only clock an offer has: a
            // listing does not expire.
            $table->timestamp('created_at')->nullable()->comment('When it was listed. An offer has no expiry - it stands until it is bought or withdrawn.');
            $table->timestamp('updated_at')->nullable()->comment('When the row was last changed. Nothing edits an offer today: the price is set once and a change of mind is a withdrawal.');

            $table->unique('inventory_item_id', 'uq_market_offers_row');

            // "What is for sale", cheapest first, which is the only listing
            // this table has.
            $table->index('unit_price', 'idx_market_offers_price');
        });

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(
            'ALTER TABLE `market_offers` ADD CONSTRAINT `chk_market_offers_price` CHECK (`unit_price` >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('market_offers');
    }
};
