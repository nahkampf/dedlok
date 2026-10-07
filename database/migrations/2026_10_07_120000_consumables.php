<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The consumable: an item that does something once, at once, when it is used (a medkit). The same shape a drug,
 * software and a weapon have: an item with a row, and nothing on `items` says which.
 *
 * NOT a drug, and not a modifier. A drug is an effect that lasts and is read by the resolver while it lasts; this
 * changes a STORED number (current hit points) a single time and is gone, which is why the modifier editor, whose
 * targets are resolved stats, had nothing to offer a medkit.
 *
 * `effects` is a list of {kind, amount}, kinds from App\Items\UseEffect. A new kind is a case, not a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumables', function (Blueprint $table): void {
            $table->comment('The consumable half of an item: what using one copy does, once. An item with a row here is a consumable. Written only by App\\Items\\ConsumableEditor.');

            $table->id()->comment('Primary key.');
            $table->foreignId('item_id')->unique()->comment('The catalogue row this is the consumable half of. One row per item.')
                ->constrained('items')->cascadeOnDelete();
            $table->json('effects')->comment('What using one copy does: a JSON list of {kind, amount}, kinds from App\\Items\\UseEffect (heal_hitpoints). Applied in order, once, when a copy is spent.');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumables');
    }
};
