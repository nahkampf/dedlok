<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rounds in a weapon. COMBAT.md.
 *
 * The magazine belongs to a COPY of a weapon, not to the catalogue entry, so it lives on the
 * inventory row — `weapons` is a definition and every copy of a pistol would share one magazine.
 * It points at the ammunition ITEM and not at an inventory row: ammunition stacks, and a row id
 * does not survive its pile splitting and merging (the `run_loadout` cookie keeps item ids for
 * the same reason).
 *
 * No CHECK ties the two columns together. `loaded_ammo_id` has a delete rule, and MySQL refuses
 * a CHECK on such a column (error 3823). So a deleted ammunition item leaves a count behind and
 * "set together" is `App\Combat\WeaponLoader`'s rule: a NULL `loaded_ammo_id` is an empty
 * magazine whatever the count says (`InventoryItem::rounds()`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->foreignId('loaded_ammo_id')->nullable()
                ->comment('The ammunition ITEM (not a row: ammunition stacks) loaded in this weapon. NULL is an empty magazine whatever loaded_rounds says. Written only by App\Combat\WeaponLoader.')
                ->constrained('items')->nullOnDelete();
            $table->unsignedSmallInteger('loaded_rounds')->default(0)
                ->comment('Rounds in this weapon\'s magazine, never above weapons.magazine. Meaningless while loaded_ammo_id is NULL. Written only by App\Combat\WeaponLoader.');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('loaded_ammo_id');
            $table->dropColumn('loaded_rounds');
        });
    }
};
