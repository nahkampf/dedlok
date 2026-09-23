<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Items and what a character is carrying.
 *
 * Definitions live in the database, the same deliberate exception the skills
 * tables took and for the same reasons: there will be hundreds, the market has
 * to query them, and an imprint has to point at a skill row. MECHANICS.md's
 * "item definitions live in config/items.php" is superseded by this decision.
 *
 * Two inventories, not one. `container` says which a definition belongs to,
 * and it is a property of the ITEM, never a player choice: a deck shard cannot
 * be carried in a backpack and a rifle cannot be stored on a deck. Everything
 * that lists an inventory filters on it.
 *
 * Neither inventory has a capacity yet. `weight` and `size` are carried so the
 * data is already there when one arrives, and nothing enforces them.
 */
return new class extends Migration
{
    /** Duplicated on purpose: a migration is historical and must not follow the model. */
    private const CONTAINERS = ['physical', 'data'];

    private const USE_OPS = ['inject_skill'];

    private const MAX_TIER = 5;

    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();

            // Permanent. Inventory rows and lang keys reference it; retire an
            // item, never rename or renumber its slug.
            $table->string('slug', 100)->unique();

            // Which of the two inventories this belongs in.
            $table->string('container', 20)->index();

            // Rarity, 1..5. Drives the .t1-.t5 classes in the UI kit and the
            // items.tiers.* lang strings; carries no mechanics of its own.
            $table->unsignedTinyInteger('tier')->default(1);

            // Whether more than one may sit on a single inventory row. See the
            // inventory_items comment for why that is the same question as
            // "may this item have a quantity above 1".
            $table->boolean('stackable')->default(true);

            // What using it does. NULL means it cannot be used at all, which
            // is most items. The op set is closed and validated, so adding a
            // usable item is a row, never new PHP.
            $table->string('use_op', 40)->nullable();

            // For use_op = inject_skill: the skill the book teaches. Restricted
            // rather than cascaded because a skill that something still points
            // at must not be deletable quietly.
            $table->foreignId('use_skill_id')->nullable()
                ->constrained('skills')->restrictOnDelete();

            // Unenforced today. Physical items have weight, data has size.
            $table->decimal('weight', 8, 2)->default(0);
            $table->unsignedInteger('size')->default(0);

            $table->unsignedInteger('base_cost')->default(0);
            $table->boolean('on_market')->default(true)->index();

            $table->timestamps();
        });

        /*
         * One row per (character, item), with a quantity — not one row per
         * physical object. Nothing carries per-instance state yet (no rolled
         * quality, no durability, no charges), so a second row would be an
         * exact duplicate of the first and the unique key is what keeps the
         * inventory honest.
         *
         * When items do get per-instance state, this is where it changes: drop
         * the unique key, keep quantity at 1 for those, add a data column.
         * `stackable` already marks which definitions could never merge.
         */
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('quantity')->default(1);

            $table->timestamps();

            $table->unique(['character_id', 'item_id']);
        });

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $containers = $this->quoted(self::CONTAINERS);
        $ops = $this->quoted(self::USE_OPS);
        $maxTier = self::MAX_TIER;

        DB::statement("ALTER TABLE `items` ADD CONSTRAINT `chk_items_container` CHECK (`container` IN ({$containers}))");
        DB::statement("ALTER TABLE `items` ADD CONSTRAINT `chk_items_tier` CHECK (`tier` BETWEEN 1 AND {$maxTier})");
        DB::statement("ALTER TABLE `items` ADD CONSTRAINT `chk_items_use_op` CHECK (`use_op` IS NULL OR `use_op` IN ({$ops}))");

        // An imprint that teaches nothing is not an imprint. The pair has to
        // be consistent in both directions, or App\Inventory\SkillInjector has
        // to defend against a row that cannot exist.
        DB::statement(
            'ALTER TABLE `items` ADD CONSTRAINT `chk_items_inject_needs_skill` CHECK ('.
            "(`use_op` = 'inject_skill' AND `use_skill_id` IS NOT NULL) OR ".
            "(`use_op` <> 'inject_skill' AND `use_skill_id` IS NULL) OR ".
            '(`use_op` IS NULL AND `use_skill_id` IS NULL))'
        );

        // Quantity 0 means the row should have been deleted.
        DB::statement('ALTER TABLE `inventory_items` ADD CONSTRAINT `chk_inventory_quantity` CHECK (`quantity` >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('items');
    }

    /** @param  list<string>  $values */
    private function quoted(array $values): string
    {
        return "'".implode("', '", $values)."'";
    }
};
