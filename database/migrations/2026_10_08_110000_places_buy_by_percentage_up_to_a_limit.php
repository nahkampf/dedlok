<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a place pays becomes a percentage, and how much it will take becomes a limit.
 *
 * ## Percent, not a price
 *
 * `location_buys.price` was a flat figure, or NULL for `city.sell.fraction` (0.4) of the item's cost. It is a
 * percentage of the item's stock price now, 100 by default, for locations and exchanges alike. An existing
 * flat price becomes the percentage that pays it today; NULL becomes 40, the fraction it used.
 *
 * ## A limit that resets
 *
 * `max_quantity` is how many of the item the buyer will take per window (NULL: no limit), `bought` how many it
 * has taken in this one, and `window_started_at` the boundary the window began on. The window is
 * `city.sell.reset_hours` long and settles lazily under the buyer's lock, as a shop's restock does.
 *
 * ## Exchanges
 *
 * An entity's desks buy by the same rules, scoped like its loyalty shelf (an entity and a range of desk
 * grades): `entity_buys`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('location_buys', function (Blueprint $table): void {
            $table->unsignedSmallInteger('percent')->default(100)->after('item_id')->comment('Percent of the item\'s stock price this place pays. 100 is full price; a file row pays this share of its own value.');
            $table->unsignedInteger('max_quantity')->nullable()->after('percent')->comment('How many of the item this place buys per reset window. NULL means no limit.');
            $table->unsignedInteger('bought')->default(0)->after('max_quantity')->comment('How many it has bought in the current window. Zeroed when the window rolls over.');
            $table->timestamp('window_started_at')->nullable()->after('bought')->comment('When the current window began. Advances by whole city.sell.reset_hours intervals.');
        });

        DB::table('location_buys')->orderBy('id')->each(function ($row): void {
            $cost = (int) DB::table('items')->where('id', $row->item_id)->value('base_cost');

            $percent = $row->price === null
                ? 40
                : ($cost > 0 ? (int) round($row->price / $cost * 100) : 100);

            DB::table('location_buys')->where('id', $row->id)->update(['percent' => min(65535, $percent), 'window_started_at' => now()]);
        });

        Schema::table('location_buys', function (Blueprint $table): void {
            $table->dropColumn('price');
        });

        Schema::create('entity_buys', function (Blueprint $table): void {
            $table->comment('What an entity\'s exchange desks buy from players, over a range of desk grades. Items bought vanish. Written by App\\Admin\\BuyListEditor.');
            $table->id()->comment('Surrogate key.');
            $table->foreignId('entity_id')->comment('The entity whose desks buy.')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->comment('What it buys. Deleting the item removes the listing.')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('min_grade')->default(1)->comment('Lowest desk grade that buys it.');
            $table->unsignedTinyInteger('max_grade')->default(5)->comment('Highest desk grade that buys it.');
            $table->unsignedSmallInteger('percent')->default(100)->comment('Percent of the item\'s stock price paid. A file row pays this share of its own value.');
            $table->unsignedInteger('max_quantity')->nullable()->comment('How many it buys per reset window, across every desk of the entity. NULL means no limit.');
            $table->unsignedInteger('bought')->default(0)->comment('How many it has bought in the current window.');
            $table->timestamp('window_started_at')->nullable()->comment('When the current window began.');
            $table->timestamp('created_at')->nullable()->comment('Laravel bookkeeping: when the row was created.');
            $table->timestamp('updated_at')->nullable()->comment('Laravel bookkeeping: when the row was last changed.');

            $table->unique(['entity_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_buys');

        Schema::table('location_buys', function (Blueprint $table): void {
            $table->unsignedInteger('price')->nullable()->comment('Creds each. NULL means config(city.sell.fraction) of the item\'s base_cost.');
        });

        Schema::table('location_buys', function (Blueprint $table): void {
            $table->dropColumn(['percent', 'max_quantity', 'bought', 'window_started_at']);
        });
    }
};
