<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A FIGHT CHOICE: a button that opens a fight and sends the player on by how it ends. docs/COMBAT.md,
 * step 5.
 *
 * A choice names an opponent and three destinations — where to go on a win, a loss, and when the fight
 * ends with nobody winning (the player got away, or the opponent did). This is a CHOICE kind and not an
 * outcome: an outcome cannot lead anywhere, and rewards are the arrival outcomes of the win screen.
 *
 * ## The opponent restricts its own deletion
 *
 * `fight_npc_id` is `restrictOnDelete`: a Fight choice with no opponent is not a choice, so an NPC an
 * encounter uses cannot be deleted until the encounter stops using it (`NpcController` says which). No
 * CHECK touches it (MySQL error 3823 for a column with a referential action); a fight choice excluding a
 * roll, a plain next screen and outcomes is `EncounterRequest`'s rule.
 *
 * ## What the fight remembers, and why not a choice id
 *
 * The editor deletes and recreates every node and choice on each save, so a choice id does not survive an
 * edit. A fight therefore carries the three destination KEYS as strings, and the `character_encounters` row
 * it came from as a plain column (a record, not a foreign key). When it ends, the runner acts only if that
 * row is still the character's live encounter, and resolves each key against the nodes as they are then.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('encounter_choices', function (Blueprint $table): void {
            $table->foreignId('fight_npc_id')->nullable()->after('fail_key')
                ->comment('Makes this a FIGHT choice: the opponent it opens a fight with. NULL is an ordinary choice. Restricts deletion of the NPC.')
                ->constrained('npcs')->restrictOnDelete();
            $table->string('win_key', 40)->nullable()->after('fight_npc_id')->comment('Fight choice: the node to go to when the player wins. NULL closes the encounter. Only meaningful with fight_npc_id.');
            $table->string('lose_key', 40)->nullable()->after('win_key')->comment('Fight choice: the node to go to when the player is beaten, shown after they get up. NULL closes the encounter.');
            $table->string('flee_key', 40)->nullable()->after('lose_key')->comment('Fight choice: the node to go to when the fight ends with nobody winning — the player got away, OR the opponent did. No gains. NULL closes the encounter.');
        });

        Schema::table('fights', function (Blueprint $table): void {
            $table->unsignedBigInteger('encounter_row_id')->nullable()->after('melee_row_id')->comment('The character_encounters row this fight was started from, or NULL for a fight started any other way. A record, not a foreign key: when the fight ends it is acted on only if it is still the character\'s live encounter.');
            $table->string('win_key', 40)->nullable()->after('encounter_row_id')->comment('Snapshot of the starting choice\'s win destination. Node KEYS, never a choice id: the editor recreates choices on every save.');
            $table->string('lose_key', 40)->nullable()->after('win_key')->comment('Snapshot of the loss destination.');
            $table->string('flee_key', 40)->nullable()->after('lose_key')->comment('Snapshot of the nobody-won destination (the player got away, or the opponent did).');
        });
    }

    public function down(): void
    {
        Schema::table('fights', function (Blueprint $table): void {
            $table->dropColumn(['encounter_row_id', 'win_key', 'lose_key', 'flee_key']);
        });

        Schema::table('encounter_choices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('fight_npc_id');
            $table->dropColumn(['win_key', 'lose_key', 'flee_key']);
        });
    }
};
