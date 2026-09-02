<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The market's supply side: how scarce a thing is, how much of it the market
 * carries, and what has actually been bought.
 *
 * Nothing here sells anything. This is the half the restock pass needs, and it
 * is separate from the storefront on purpose: the market can be built against
 * a table that is already being stocked, rather than the other way round.
 *
 * Three pieces:
 *
 * 1. `items.rarity` — how likely the market is to restock the item at all.
 *    Distinct from `tier`, which it is easy to mistake for it: tier is what
 *    the thing IS (Standard, Tuned, Milspec, Prototype, Anomalous) and drives
 *    the .t1-.t5 colours, rarity is how hard it is to FIND. A milspec rifle
 *    can be common where you are standing.
 * 2. `items.seed_quantity` — how many the market carries when it is fully
 *    stocked. 0 means the market never stocks it, which is the default: an
 *    item has to be given a number before it shows up for sale, so nothing
 *    appears on the shelves by forgetting to say otherwise.
 * 3. `market_stock` and `market_purchases` — what is on the shelf now, and
 *    what has left them. The restock pass reads the second to decide how far
 *    to fill the first.
 */
return new class extends Migration
{
    /** Duplicated on purpose: a migration is historical and must not follow the model. */
    private const RARITIES = ['common', 'uncommon', 'rare', 'ultra_rare'];

    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            // Indexed because the restock pass and the storefront both filter
            // and group by it.
            $table->string('rarity', 20)->default('common')->after('tier')->index();

            // The full-shelf quantity. See the class comment for why 0.
            $table->unsignedInteger('seed_quantity')->default(0)->after('on_market');
        });

        /*
         * What the market is holding right now: one row per item, not one per
         * object for sale. Quantity is the shelf count and nothing else — the
         * price a buyer pays comes from `items.base_cost`, so a row here can
         * never disagree with the catalogue about what a thing costs.
         *
         * A missing row is a stock of zero, not an error. Rows are created by
         * the first restock that actually delivers something.
         */
        Schema::create('market_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->unique()->constrained()->cascadeOnDelete();

            $table->unsignedInteger('quantity')->default(0);

            // When something was last added. Null is impossible in practice —
            // a row exists because a restock created it — but the column is
            // nullable so a row can be inserted by hand without lying.
            $table->timestamp('last_seeded_at')->nullable();

            $table->timestamps();
        });

        /*
         * Every purchase, one row, forever. This is demand: the restock pass
         * sums a window of it per item and stocks more of what is selling.
         *
         * No timestamps(). A history row is written once and never touched, so
         * `updated_at` would be a column that is always equal to the other one,
         * and `purchased_at` says what the moment MEANS rather than when the
         * row happened to be inserted.
         *
         * `character_id` goes null when a character is deleted rather than
         * taking the row with it: the market's demand for an item is not
         * undone by the buyer ceasing to exist.
         */
        Schema::create('market_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('quantity');

            // What was actually paid, per unit. Stored rather than read back
            // off the item, because base_cost is expected to be tuned and a
            // receipt that changes afterwards is not a receipt.
            $table->unsignedInteger('unit_price');

            $table->timestamp('purchased_at');

            // Exactly the restock pass's query: this item, this window. Also
            // the index MySQL uses for the item_id foreign key, which is why
            // it is declared before the constraint is added.
            $table->index(['item_id', 'purchased_at']);
        });

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $rarities = "'".implode("', '", self::RARITIES)."'";

        DB::statement("ALTER TABLE `items` ADD CONSTRAINT `chk_items_rarity` CHECK (`rarity` IN ({$rarities}))");

        // A purchase of nothing is not a purchase, and it would skew demand
        // by adding a row that contributes zero volume.
        DB::statement('ALTER TABLE `market_purchases` ADD CONSTRAINT `chk_market_purchase_quantity` CHECK (`quantity` >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('market_purchases');
        Schema::dropIfExists('market_stock');

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `items` DROP CONSTRAINT `chk_items_rarity`');
        }

        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex(['rarity']);
            $table->dropColumn(['rarity', 'seed_quantity']);
        });
    }
};
