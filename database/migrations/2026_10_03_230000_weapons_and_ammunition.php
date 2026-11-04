<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Weapons, ammunition, and which of them a character is holding. docs/COMBAT.md.
 *
 * ## Items with rows
 *
 * The shape software, drugs and armour have. Nothing on `items` says an item is a weapon or
 * a round; the row is what says so. A hit's damage is FIXED — one number, never a range — and
 * for a firearm it is the weapon's AND the round's: `weapons.damage` is what a hit does with
 * its standard round, and an `ammunition` row is a set of changes to that (a signed percent,
 * a penetration, optionally a different damage profile).
 *
 * ## No riders, yet
 *
 * Stun, burning and poison are designed (docs/COMBAT.md) but nothing reads them until the fight
 * engine exists. A JSON column with no editor and no reader would be dead weight, so it
 * arrives with the thing that uses it.
 *
 * ## Two hands
 *
 * A firearm is usable only at Ranged and a blade only at Close, so a fighter holds one of each:
 * `equipped_ranged_id` and `equipped_melee_id`, `equipped_deck_id`'s own shape — a column
 * rather than a flag, so "one at a time" is not a rule anybody enforces. NULL is bare hands.
 * Both null the pointer if the row is destroyed.
 *
 * No CHECK reaches a foreign-key column (MySQL error 3823); the ones here are on plain columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weapons', function (Blueprint $table): void {
            $table->comment('The weapon half of an item. An item with one of these rows is a weapon. A hit does ONE fixed damage; for a firearm that is with its standard round.');
            $table->id()->comment('Primary key.');
            $table->foreignId('item_id')
                ->comment('The catalogue row this is the weapon half of.')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();
            $table->string('class', 16)->comment('An App\Combat\WeaponClass case: which skill aims it, and whether it is a Ranged or a Close weapon.');
            $table->unsignedSmallInteger('damage')->comment('The ONE fixed damage a hit does with its standard round, before hit quality and resistance. A base the resolver reads; never a range.');
            $table->json('damage_profile')->comment('A JSON map of damage type to percent share, summing to 100: a katana {slashing: 100}, a stun baton {blunt: 70, electrical: 30}. Each part of a hit resolves on its own against the target\'s resistance to that type.');
            $table->smallInteger('accuracy')->default(0)->comment('A signed modifier to the shooter\'s aim with this weapon. A base: modifiers move it.');
            $table->unsignedTinyInteger('rate')->default(1)->comment('Shots per round. 1 for anything that is not an automatic firearm.');
            $table->unsignedSmallInteger('magazine')->nullable()->comment('Rounds it holds before a reload. NULL for a weapon that takes no ammunition; set exactly when `calibre` is.');
            $table->string('calibre', 32)->nullable()->comment('Which ammunition fits it; an ammunition row must name the same. NULL for a weapon that takes none; set exactly when `magazine` is.');
            $table->timestamps();
        });

        Schema::create('ammunition', function (Blueprint $table): void {
            $table->comment('The ammunition half of an item: a set of changes to a hit. Stackable, and fits only a weapon of the same calibre.');
            $table->id()->comment('Primary key.');
            $table->foreignId('item_id')
                ->comment('The catalogue row this is the ammunition half of.')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();
            $table->string('calibre', 32)->comment('Which weapons take it. Matched exactly against weapons.calibre.');
            $table->smallInteger('damage')->default(0)->comment('A signed percent on the hit while this round is loaded: +25 for a hollow point, -15 for armour piercing. 0 is the weapon\'s own.');
            $table->smallInteger('penetration')->default(0)->comment('A signed percent of the target\'s RESISTANCE it ignores: +50 halves what a vest is worth, -50 makes it count half again. Only resistances are scaled; a vulnerability or an immunity is not.');
            $table->json('damage_profile')->nullable()->comment('Optional: replaces the weapon\'s damage profile while this round is loaded (an incendiary round adding a thermal share). NULL keeps the weapon\'s own.');
            $table->timestamps();
        });

        Schema::table('characters', function (Blueprint $table): void {
            $table->foreignId('equipped_ranged_id')->nullable()->after('equipped_deck_id')
                ->comment('The Ranged weapon this character is holding, or NULL for none. A column like equipped_deck_id: one value, so "one at a time" is not a rule anybody enforces. Written only by App\Combat\WeaponWielder.')
                ->constrained('inventory_items')->nullOnDelete();
            $table->foreignId('equipped_melee_id')->nullable()->after('equipped_ranged_id')
                ->comment('The Close weapon this character is holding, or NULL for bare hands. Written only by App\Combat\WeaponWielder.')
                ->constrained('inventory_items')->nullOnDelete();
        });

        if (DB::getDriverName() !== 'sqlite') {
            $classes = "'pistol','smg','rifle','shotgun','blade','blunt','unarmed','explosive'";
            DB::statement("ALTER TABLE weapons ADD CONSTRAINT chk_weapons_class CHECK (class IN ({$classes}))");
            DB::statement('ALTER TABLE weapons ADD CONSTRAINT chk_weapons_damage CHECK (damage >= 1)');
            DB::statement('ALTER TABLE weapons ADD CONSTRAINT chk_weapons_calibre_pair CHECK ((calibre IS NULL) = (magazine IS NULL))');
        }
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->dropForeign(['equipped_ranged_id']);
            $table->dropForeign(['equipped_melee_id']);
            $table->dropColumn(['equipped_ranged_id', 'equipped_melee_id']);
        });

        Schema::dropIfExists('ammunition');
        Schema::dropIfExists('weapons');
    }
};
