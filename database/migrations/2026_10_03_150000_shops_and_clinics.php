<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The first two things a location can DO. A location is a shop when it has
 * rows in `shop_items` and a clinic when it has rows in `clinic_services`; there
 * is no feature table, because a flag beside the rows would be a second answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_items', function (Blueprint $table): void {
            $table->comment('What a location sells: unlimited stock, so no restock clock. Replaced wholesale by App\\City\\FeatureEditor; nothing references a row id.');
            $table->id()->comment('Surrogate key.');
            $table->foreignId('location_id')->comment('The shop.')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->comment('What it sells. Deleting the item removes the listing.')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('price')->nullable()->comment('Creds each. NULL means the item\'s own base_cost. Ignores items.on_market: a shop may sell what the market does not.');
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
        Schema::dropIfExists('shop_items');
    }
};
