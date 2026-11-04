<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Armour, worn per body part. docs/COMBAT.md, *Body parts and armour*.
 *
 * ## An armour piece is an item with an `armour` row
 *
 * The shape software and drugs have. Nothing on `items` says an item is armour; the row
 * is what says so, and its RESISTANCES stay on `items.modifiers` like an implant's — a
 * thing with a row carries its modifiers on that row. What the row holds is the one fact
 * a bare item has no place for: which slots of the body it covers. A helmet covers one,
 * a plate one, a full set all six, and a set is ONE item that takes several.
 *
 * ## What is worn is a row per slot
 *
 * `worn_armour` is (character, slot) -> the inventory row wearing it, unique on the pair,
 * so "one piece per slot" is a key and not a rule anybody enforces. A piece that covers
 * four slots has four rows pointing at the same inventory row, which is what lets a hit to
 * the left arm ask "what is on the left arm?" with one lookup. The inventory row cascades,
 * so destroying a worn piece takes it off.
 *
 * No CHECK reaches a foreign-key column here (MySQL refuses one on a cascading key, error
 * 3823); the only CHECK is on the plain `slot` column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('armour', function (Blueprint $table): void {
            $table->comment('The armour half of an item. An item with one of these rows is armour; its resistances stay on `items.modifiers`, like an implant\'s.');
            $table->id()->comment('Primary key.');
            $table->foreignId('item_id')
                ->comment('The catalogue row this is the armour half of.')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();
            $table->json('slots')->comment('A JSON list of App\Combat\ArmourSlot values this piece covers: one for a helmet, several for a set. Non-empty (held by the request). A resistance on the item counts only when a hit lands in one of these.');
            $table->timestamps();
        });

        Schema::create('worn_armour', function (Blueprint $table): void {
            $table->comment('What a character is wearing, one row per slot. A piece covering four slots is four rows pointing at one inventory row.');
            $table->id()->comment('Primary key.');
            $table->foreignId('character_id')
                ->comment('Who is wearing it. Cascades: a deleted character takes it off.')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('slot', 12)->comment('An App\Combat\ArmourSlot value. Unique per character: one piece in a slot, and a swap vacates the old one first.');
            $table->foreignId('inventory_item_id')
                ->comment('The inventory row wearing this slot. Cascades: destroying a worn piece takes it off. Worn armour stays in the stash grid, as the equipped deck does.')
                ->constrained('inventory_items')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['character_id', 'slot']);
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE worn_armour ADD CONSTRAINT chk_worn_armour_slot CHECK (slot IN ('head','torso','arm_left','arm_right','leg_left','leg_right'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('worn_armour');
        Schema::dropIfExists('armour');
    }
};
