<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Items that hold other items, and the per-instance identity that needs.
 *
 * The case this exists for is a memory card: a PHYSICAL object that carries
 * DATA. `container` could not answer that, because it was answering a
 * different question — a memory card is matter that holds bits, and until now
 * "what kind of thing is this" and "which of the two lists does it show in"
 * had the same answer for every item in the game.
 *
 * They separate here. `container` keeps saying what kind of thing an item is;
 * where a row actually sits now comes from `parent_id`.
 *
 * Two consequences, both of them the reason this migration is not one column:
 *
 * 1. A card's contents have to belong to the card, not to the character's
 *    loose pile — hence `parent_id`, and `whereNull('parent_id')` in every
 *    listing that means "loose".
 * 2. Two memory cards are no longer interchangeable. The old unique key on
 *    (character_id, item_id) said they were: a card with an imprint on it and
 *    a blank one collapsed into a single row with quantity 2. That key has to
 *    go, and something weaker has to replace it or stacking stops working.
 */
return new class extends Migration
{
    /** Duplicated on purpose: a migration is historical and must not follow the model. */
    private const CONTAINERS = ['physical', 'data'];

    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            // How much this item can hold. 0 means it holds nothing, which is
            // almost everything: being a container is the exception.
            $table->unsignedInteger('capacity')->default(0)->after('size');

            // Which kind of thing it accepts. A memory card takes data and not
            // a rifle. NULL when it is not a container at all — the pair is
            // constrained below so the two can never disagree.
            $table->string('accepts', 20)->nullable()->after('capacity');
        });

        Schema::table('inventory_items', function (Blueprint $table) {
            // What this row is inside, if anything. Self-referencing: the card
            // is an inventory row like any other. Cascading because destroying
            // the card destroys what was written on it — that is the fiction
            // and it is also what stops orphan rows existing.
            $table->foreignId('parent_id')->nullable()->after('item_id')
                ->constrained('inventory_items')->cascadeOnDelete();

            /*
             * What may not be duplicated.
             *
             * NULL for anything unstackable, and MySQL and SQLite both treat
             * NULLs in a unique index as distinct — so unstackable items get
             * as many rows as the player has objects, which is the whole
             * point. Stackable items get a key naming the pile they belong to
             * (character, item, and which container they are in), so two rows
             * for the same pile still collide the way they always did.
             *
             * It is derived, never set by hand: InventoryItem computes it on
             * every save. A generated column would be better and is not
             * available — `stackable` lives on `items`, and a generated column
             * can only read its own row.
             */
            $table->string('stack_key', 80)->nullable()->after('quantity');
        });

        $this->backfillStackKeys();

        Schema::table('inventory_items', function (Blueprint $table) {
            // Before dropping the composite: on MySQL it is the index holding
            // up the character_id foreign key, and dropping it out from under
            // the constraint fails with errno 1553.
            $table->index('character_id');
            $table->unique('stack_key');
            $table->dropUnique(['character_id', 'item_id']);
        });

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $containers = "'".implode("', '", self::CONTAINERS)."'";

        DB::statement("ALTER TABLE `items` ADD CONSTRAINT `chk_items_accepts` CHECK (`accepts` IS NULL OR `accepts` IN ({$containers}))");

        // Capacity without a kind holds anything; a kind without capacity
        // holds nothing while claiming otherwise. Neither is a container.
        DB::statement(
            'ALTER TABLE `items` ADD CONSTRAINT `chk_items_capacity_pair` CHECK ('.
            '(`capacity` = 0 AND `accepts` IS NULL) OR '.
            '(`capacity` > 0 AND `accepts` IS NOT NULL))'
        );
    }

    /**
     * Give every existing row the key it would have been saved with.
     *
     * Written in the query builder rather than one UPDATE ... JOIN so it runs
     * on SQLite too, and deliberately not through the model: a migration must
     * keep working after the model moves on.
     */
    private function backfillStackKeys(): void
    {
        $stackable = DB::table('items')->where('stackable', true)->pluck('id')->all();

        if ($stackable === []) {
            return;
        }

        DB::table('inventory_items')
            ->whereIn('item_id', $stackable)
            ->orderBy('id')
            ->chunk(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('inventory_items')->where('id', $row->id)->update([
                        'stack_key' => "{$row->character_id}:{$row->item_id}:0",
                    ]);
                }
            });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `items` DROP CONSTRAINT `chk_items_capacity_pair`');
            DB::statement('ALTER TABLE `items` DROP CONSTRAINT `chk_items_accepts`');
        }

        Schema::table('inventory_items', function (Blueprint $table) {
            // Contents first: the composite key coming back cannot describe
            // two cards, so anything nested has to stop existing.
            $table->dropUnique(['stack_key']);
            $table->dropForeign(['parent_id']);
        });

        DB::table('inventory_items')->whereNotNull('parent_id')->delete();

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn(['parent_id', 'stack_key']);
            $table->unique(['character_id', 'item_id']);
            $table->dropIndex(['character_id']);
        });

        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['capacity', 'accepts']);
        });
    }
};
