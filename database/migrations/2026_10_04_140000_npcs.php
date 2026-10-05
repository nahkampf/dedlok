<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NPCs: the opponents of a fight. COMBAT.md, *The model still to build*.
 *
 * An NPC is a row of NUMBERS and three pointers at items — a ranged weapon, a melee weapon and a
 * standing round — so everything a weapon does lives on the weapon and an editor changes it in one
 * place. Its name and flavour are on the row, because the thing has a row.
 *
 * ## Only what something reads
 *
 * Nothing here is modelled against an unwritten feature: whose NPC it is (standing), its loot and
 * its defeat tags arrive with the step that pays them out (COMBAT.md, step 6), the encounter that
 * starts a fight with the step that adds that choice. Columns modelled against code that does not
 * exist get modelled wrong.
 *
 * ## No CHECK touches a foreign-key column
 *
 * The three item pointers null on delete (an NPC whose weapon is deleted goes unarmed, rather than
 * the delete being refused), and MySQL refuses a CHECK on a column with a referential action
 * (error 3823). Which kind of weapon belongs in which hand is `NpcRequest`'s rule. The CHECKs here
 * are on plain columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('npcs', function (Blueprint $table): void {
            $table->comment('An opponent in a fight: numbers, and which items it fights with. Authored content; nothing is seeded.');
            $table->id()->comment('Primary key. Also the name of its picture: public/images/npcs/<id>.jpg (3:4).');
            $table->string('slug', 50)->unique()->comment('Stable machine name. Permanent: other rows will name it.');
            $table->string('name', 100)->comment('What the fight log and the screen call it.');
            $table->text('flavor')->nullable()->comment('A line or two about it for the fight screen. NULL is ordinary.');
            $table->unsignedTinyInteger('grade')->default(1)->comment('Difficulty band 1-5. Says how dangerous it is on a list; payouts will be priced by it.');
            $table->unsignedSmallInteger('hitpoints')->comment('Hit points it starts a fight with. A snapshot is taken when a fight opens, so editing this never touches a fight in progress.');
            $table->decimal('aim', 5, 1)->default(10)->comment('How well it places a shot: the value its attacks roll with, on the scale of aim.<class> (a starting character is near 13). The weapon\'s accuracy is added.');
            $table->decimal('defence', 5, 1)->default(8)->comment('What an attack against it rolls against, on the same scale as aim. A stand-in until dodge exists.');
            $table->decimal('pursuit', 5, 1)->default(8)->comment('How hard it is to get away from: what a flight roll is made against.');
            $table->decimal('flees_at', 3, 2)->nullable()->comment('A share of its hit points (0.25) below which it tries to Disengage, as a roll that can fail. NULL never flees.');
            $table->foreignId('ranged_weapon_id')->nullable()
                ->comment('The weapon item it uses at Ranged, or NULL for none. Must be a Ranged class (NpcRequest). Nulled if the item is deleted, which leaves it unarmed at range.')
                ->constrained('items')->nullOnDelete();
            $table->foreignId('melee_weapon_id')->nullable()
                ->comment('The weapon item it uses at Close, or NULL for bare hands. Must be a Close class (NpcRequest). Nulled if the item is deleted.')
                ->constrained('items')->nullOnDelete();
            $table->foreignId('ammo_item_id')->nullable()
                ->comment('The standing round its ranged weapon fires: its damage change, penetration, profile and riders apply, and it never runs out (an NPC does not count ammunition). NULL fires the weapon as it is. Calibre must match (NpcRequest).')
                ->constrained('items')->nullOnDelete();
            $table->json('resistances')->nullable()->comment('Percent off by damage type, as a JSON map: {"penetrating": 30, "thermal": -25}. Negative is a vulnerability; 100 or more is an immunity no round pierces. NULL is none.');
            $table->json('slot_resistances')->nullable()->comment('Extra percent off by body part and damage type, added to resistances for a hit that lands there: {"head": {"penetrating": 40}}. NULL is none.');
            $table->timestamps();
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE npcs ADD CONSTRAINT chk_npcs_grade CHECK (grade BETWEEN 1 AND 5)');
            DB::statement('ALTER TABLE npcs ADD CONSTRAINT chk_npcs_hitpoints CHECK (hitpoints >= 1)');
            DB::statement('ALTER TABLE npcs ADD CONSTRAINT chk_npcs_flees_at CHECK (flees_at IS NULL OR flees_at BETWEEN 0 AND 1)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('npcs');
    }
};
