<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A module, installed in a socket of a deck somebody owns.
 *
 * This is the row `deck_slots` has been waiting for since it was created: the
 * reason sockets are rows rather than counts is that a count cannot be pointed
 * at, and this is the thing that points.
 *
 * Three references, because three things have to be named and no two of them
 * are the same kind of thing:
 *
 * - `deck_id` — WHICH DECK. An inventory row, not an item. Two players holding
 *   a Kurogane Explorer have different modules in them, and the definition
 *   cannot tell them apart.
 * - `deck_slot_id` — WHICH SOCKET. That belongs to the definition: "the CPU
 *   socket of a Kurogane Explorer". Only together with `deck_id` is it a
 *   place in the world.
 * - `module_id` — WHAT WENT IN. Also an inventory row: the specific stick,
 *   not the kind of stick.
 *
 * Not `inventory_items.parent_id`. Containment is about bulk — a size against
 * a capacity, anything that fits — and a socket is the opposite of that: one
 * named place that takes one particular thing. Expressing one through the
 * other would mean special-casing every containment rule (`canHold`, capacity,
 * the depth cap) for a case none of them are about.
 *
 * Cascades, all three, and each says something different:
 * - Destroy the deck object and its fittings go, but the MODULES SURVIVE as
 *   loose rows. Deliberately unlike a memory shard, which destroys what is
 *   written on it: crushing a shard destroys data, and stripping a deck for
 *   parts is what anyone would do before letting go of it.
 * - Destroy the module and the fitting goes with it. The socket is empty.
 * - Remove the SOCKET — an admin redesigning the deck — and the fitting goes,
 *   which pops the module back into the owner's hands. App\Decks\HardwareEditor
 *   is what keeps that rare: it diffs sockets now rather than replacing them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deck_fittings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('deck_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->foreignId('deck_slot_id')->constrained('deck_slots')->cascadeOnDelete();
            $table->foreignId('module_id')->constrained('inventory_items')->cascadeOnDelete();

            $table->timestamps();

            // One module per physical socket. The pair is the place: the slot
            // alone is shared by every copy of the deck in the game.
            $table->unique(['deck_id', 'deck_slot_id']);

            // A module is in at most one socket. Without this the same stick
            // could be fitted into two decks at once and counted twice by
            // everything that ever sums what a deck is worth.
            $table->unique('module_id');
        });

        /*
         * Modules stop stacking, which is the other half of this feature.
         *
         * A fitting points at an inventory ROW. Two identical sticks sharing
         * one row with a quantity of 2 cannot be pointed at individually —
         * fitting one would mean splitting the stack, and the row that came
         * back would be a different row from the one the player chose.
         *
         * Decks have been refused permission to stack since the item editor
         * was written, for the same reason one step earlier. This is the rest
         * of it. Existing rows are split by the same rule below.
         */
        DB::table('items')->whereNotNull('hardware')->update(['stackable' => false]);

        /*
         * Any pile of modules a player already has becomes one row per object.
         *
         * `stack_key` is derived by the model on save and is NULL for
         * unstackable items, so the rows written here are collapsed back
         * together by nothing.
         */
        $piles = DB::table('inventory_items')
            ->join('items', 'items.id', '=', 'inventory_items.item_id')
            ->whereNotNull('items.hardware')
            ->where('inventory_items.quantity', '>', 1)
            ->select('inventory_items.*')
            ->get();

        foreach ($piles as $pile) {
            DB::table('inventory_items')
                ->where('id', $pile->id)
                ->update(['quantity' => 1, 'stack_key' => null]);

            for ($copy = 1; $copy < $pile->quantity; $copy++) {
                DB::table('inventory_items')->insert([
                    'character_id' => $pile->character_id,
                    'item_id' => $pile->item_id,
                    'parent_id' => $pile->parent_id,
                    'quantity' => 1,
                    'stack_key' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('deck_fittings');

        // The piles are not put back together. Which rows were once one row
        // is not recorded anywhere, and inventing an answer would be worse
        // than leaving a player with two sticks that are genuinely two sticks.
        DB::table('items')->whereNotNull('hardware')->where('hardware', '!=', 'deck')
            ->update(['stackable' => true]);
    }
};
