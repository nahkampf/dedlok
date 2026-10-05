<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a fight pays and what it costs. COMBAT.md, step 6.
 *
 * The columns COMBAT.md held back until the code that reads them existed: whose an opponent is (for
 * standing), what it carries (loot) and what it does to somebody it beats (tags). The pay band is
 * not a column: it is `config/combat.php`, by grade.
 *
 * ## Loot is its own table
 *
 * `loot_tables` is the grid's: rarity weights per machine type and grade band. An opponent's drop is a
 * short list of items, each with its own chance and quantity, replaced wholesale by the editor.
 *
 * ## A fight remembers what its ending reads
 *
 * Like the opponent's resistances, the terms are copied onto the fight when it opens, so an opponent
 * edited or deleted mid-fight changes nothing. `entity_id` is a plain column on the fight, never a
 * foreign key: a row that REMEMBERS something must not cascade from it (nor stop it being deleted).
 *
 * ## No CHECK on a column with a delete rule
 *
 * MySQL refuses it (error 3823). The CHECKs below are on plain columns only; which items may be loot
 * and which entity may own an opponent are `NpcRequest`'s.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('npcs', function (Blueprint $table): void {
            $table->foreignId('entity_id')->nullable()->after('pursuit')
                ->comment('Whose it is: the entity whose standing moves when the player beats it. NULL is nobody, and nothing moves. Nulled if the entity is deleted. NpcRequest holds that it is interactable.')
                ->constrained('entities')->nullOnDelete();
            $table->json('tags')->nullable()->after('entity_id')
                ->comment('App\\Combat\\CombatTag values it carries: what it does to somebody it beats. NULL means the default, [mugged]; ANY list REPLACES the default (["maim"] maims and does not rob). Opposite to ICE, where null and [] are one answer.');
        });

        Schema::create('npc_loot', function (Blueprint $table): void {
            $table->comment('What an opponent may drop when it is beaten: one row per item. Replaced wholesale by the NPC editor.');
            $table->id()->comment('Primary key.');
            $table->foreignId('npc_id')->comment('The opponent that drops it. Rows go with it.')
                ->constrained('npcs')->cascadeOnDelete();
            $table->foreignId('item_id')->comment('The item dropped. Physical items only (NpcRequest): a drop cannot be refused for want of storage. Rows go with the item.')
                ->constrained('items')->cascadeOnDelete();
            $table->unsignedTinyInteger('chance')->comment('Percent chance, 1-100, rolled once per fight won.');
            $table->unsignedSmallInteger('min_quantity')->default(1)->comment('The fewest copies a drop is.');
            $table->unsignedSmallInteger('max_quantity')->default(1)->comment('The most copies a drop is. At least min_quantity.');
            $table->unique(['npc_id', 'item_id']);
        });

        Schema::table('fights', function (Blueprint $table): void {
            $table->unsignedBigInteger('entity_id')->nullable()->after('grade')
                ->comment('Snapshot of npcs.entity_id when the fight opened. A memory, not a key: no foreign key, so deleting the entity neither cascades nor is refused.');
            $table->json('tags')->nullable()->after('entity_id')
                ->comment('Snapshot of npcs.tags when the fight opened. NULL means the default, [mugged].');
            $table->json('loot')->nullable()->after('tags')
                ->comment('Snapshot of the opponent\'s npc_loot when the fight opened: a list of {item_id, chance, min, max}. An item since deleted is skipped when the drop is rolled.');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE npc_loot ADD CONSTRAINT chk_npc_loot_chance CHECK (chance BETWEEN 1 AND 100)');
            DB::statement('ALTER TABLE npc_loot ADD CONSTRAINT chk_npc_loot_quantity CHECK (min_quantity >= 1 AND max_quantity >= min_quantity)');
        }
    }

    public function down(): void
    {
        Schema::table('fights', function (Blueprint $table): void {
            $table->dropColumn(['entity_id', 'tags', 'loot']);
        });

        Schema::dropIfExists('npc_loot');

        Schema::table('npcs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('entity_id');
            $table->dropColumn('tags');
        });
    }
};
