<?php

use App\Inventory\ItemGranter;
use App\Models\Character;
use App\Models\Item;
use Illuminate\Database\Migrations\Migration;

/**
 * One Utility Crafting imprint into Noodle's hands. The imprint is off the market and nothing drops it, so this is how it
 * reaches anyone. A game without a character called Noodle, or without the imprint, is left alone; no schema changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $noodle = Character::query()->where('handle', 'Noodle')->first();
        $imprint = Item::query()->where('slug', 'imprint_utility_crafting')->first();

        if ($noodle !== null && $imprint !== null) {
            ItemGranter::grant($noodle, $imprint);
        }
    }

    public function down(): void
    {
        // A granted imprint may have been injected or traded since; there is nothing safe to take back.
    }
};
