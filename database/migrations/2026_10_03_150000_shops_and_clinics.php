<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The first things a location can DO. A location is a shop when it has
 * rows in `shop_items` and a clinic when it has rows in `clinic_services`; there
 * is no feature table, because a flag beside the rows would be a second answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_items', function (Blueprint $table): void {
            $table->comment('What a location sells. Stock is per listing and restocks lazily (App\\City\\ShopKeeper::settle). Edited as a DIFF by App\\City\\FeatureEditor, because a listing now carries a stock count worth keeping.');
            $table->id()->comment('Surrogate key.');
            $table->foreignId('location_id')->comment('The shop.')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->comment('What it sells. Deleting the item removes the listing.')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('price')->nullable()->comment('Creds each. NULL means the item\'s own base_cost. Ignores items.on_market: a shop may sell what the market does not.');
            $table->unsignedInteger('max_stock')->nullable()->comment('The most it holds. NULL means unlimited: stock and the restock columns are then ignored.');
            $table->unsignedInteger('stock')->default(0)->comment('How many are on the shelf now, as of restocked_at. Written only by ShopKeeper (a sale, a lazy restock) and the editor (a new listing starts full).');
            $table->unsignedInteger('restock_amount')->default(0)->comment('How many each restock interval adds, up to max_stock. 0 never restocks.');
            $table->unsignedSmallInteger('restock_hours')->nullable()->comment('Hours between restocks. NULL never restocks.');
            $table->timestamp('restocked_at')->nullable()->comment('The last restock boundary settled. Advanced by whole intervals, so a full shelf cannot bank restocks.');
            $table->timestamp('created_at')->nullable()->comment('Laravel bookkeeping: when the row was created.');
            $table->timestamp('updated_at')->nullable()->comment('Laravel bookkeeping: when the row was last changed.');

            $table->unique(['location_id', 'item_id']);
        });

        Schema::create('location_buys', function (Blueprint $table): void {
            $table->comment('What a location will BUY from players. Items bought vanish. Replaced wholesale by App\\City\\FeatureEditor.');
            $table->id()->comment('Surrogate key.');
            $table->foreignId('location_id')->comment('The place that buys.')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->comment('What it buys. Deleting the item removes the listing.')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('price')->nullable()->comment('Creds each. NULL means config(city.sell.fraction) of the item\'s base_cost.');
            $table->timestamp('created_at')->nullable()->comment('Laravel bookkeeping: when the row was created.');
            $table->timestamp('updated_at')->nullable()->comment('Laravel bookkeeping: when the row was last changed.');

            $table->unique(['location_id', 'item_id']);
        });

        Schema::create('clinic_services', function (Blueprint $table): void {
            $table->comment('What a location treats. Priced per point restored. Replaced wholesale by App\\City\\FeatureEditor.');
            $table->id()->comment('Surrogate key.');
            $table->foreignId('location_id')->comment('The clinic.')->constrained()->cascadeOnDelete();
            $table->string('service', 30)->comment('App\\City\\ClinicService: hitpoints, synaptic_integrity, neural_damage or physical_trauma.');
            $table->unsignedInteger('price')->comment('Creds per point restored or mended. The total is worked out under a lock when treated.');
            $table->timestamp('created_at')->nullable()->comment('Laravel bookkeeping: when the row was created.');
            $table->timestamp('updated_at')->nullable()->comment('Laravel bookkeeping: when the row was last changed.');

            $table->unique(['location_id', 'service']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE clinic_services ADD CONSTRAINT chk_clinic_services_service CHECK (service IN ('hitpoints', 'synaptic_integrity', 'neural_damage', 'physical_trauma'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_services');
        Schema::dropIfExists('location_buys');
        Schema::dropIfExists('shop_items');
    }
};
