<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The belt: three slots on the body that hold what is meant to be used in a hurry. Drugs, for now.
 *
 * A COLUMN ON THE ROW, `belt_slot`, in the shape `installed_at` and `running_on_deck_id` have: where something is
 * is a fact about the particular copy. The slot is one of the belt's three (1, 2, 3); NULL is the ordinary state,
 * not on the belt. Unique with the owner, so two things cannot share a slot, and nothing needs a table of its own
 * because a slot holds one row and a row holds one slot.
 *
 * `InventoryItem::stackKey()` learns it too: a belted pile does not merge with the loose pile of the same item,
 * exactly as a running program does not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->unsignedTinyInteger('belt_slot')->nullable()->after('installed_at')
                ->comment('Which of the belt\'s slots this row is carried in (1 to config equipment.belt_slots). NULL is not on the belt. A belted pile does not stack with the loose one, so BeltEquipper merges it back when it comes off.');
            $table->unique(['character_id', 'belt_slot'], 'inventory_items_belt_slot_unique');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->dropUnique('inventory_items_belt_slot_unique');
            $table->dropColumn('belt_slot');
        });
    }
};
