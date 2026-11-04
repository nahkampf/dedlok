<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A fight, as a durable row. docs/COMBAT.md, step 4 — the first step that writes to the game.
 *
 * `runs` and `hunts`' own reason: a fight is interactive, so the middle of one has to survive between
 * requests. `characters.current_fight_id` is what "in a fight" means (a column, like
 * `current_run_id`), and `App\Combat\Fights` is the only thing that writes either.
 *
 * ## The state is a snapshot, kept whole
 *
 * `state` is `FightState::toArray()` — both sides, the range, the round, the log. The opponent is a
 * snapshot too: its name, grade and both resistance maps are copied onto the row when the fight opens,
 * because an administrator may edit or delete the NPC mid-fight and the fight must carry on as it was.
 * So `npc_id` is a MEMORY of where it came from, not a dependency: `nullOnDelete`, never a cascade
 * (a row that remembers something must not vanish with it).
 *
 * ## Which inventory rows
 *
 * The engine's weapons carry no row ids, so the rows the player's two weapons came from are written
 * down when the fight opens, and every round's ammunition goes back to THOSE rows. Plain columns, no
 * foreign key: they are a record of what was held, and the writer checks they are still the equipped
 * ones rather than the database refusing.
 *
 * No CHECK touches a foreign-key column (MySQL error 3823).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fights', function (Blueprint $table): void {
            $table->comment('One fight between a character and an opponent. Durable because a fight is interactive. Written only by App\Combat\Fights.');
            $table->id()->comment('Primary key. Written into characters.current_fight_id while the fight is live.');
            $table->foreignId('character_id')
                ->comment('Who is fighting. The fight goes with them.')
                ->constrained()->cascadeOnDelete();
            $table->foreignId('npc_id')->nullable()
                ->comment('Where the opponent came from. A MEMORY: nulled if the NPC is deleted, and the fight carries on from its own snapshot below.')
                ->constrained('npcs')->nullOnDelete();
            $table->string('opponent_name', 100)->comment('The opponent\'s name when the fight opened. Snapshot: editing the NPC never changes it.');
            $table->unsignedTinyInteger('grade')->comment('The opponent\'s grade when the fight opened. Snapshot.');
            $table->json('resistances')->nullable()->comment('The opponent\'s resistance map when the fight opened (damage type => percent off). Snapshot: the fight resolves hits from this, never the live NPC row.');
            $table->json('slot_resistances')->nullable()->comment('The opponent\'s per-body-part resistances when the fight opened. Snapshot.');
            $table->unsignedBigInteger('ranged_row_id')->nullable()->comment('The inventory row the player\'s ranged weapon came from when the fight opened. A record, not a foreign key: ammunition spent or loaded goes back to this row, and the writer checks it is still the equipped one.');
            $table->unsignedBigInteger('melee_row_id')->nullable()->comment('The inventory row the player\'s melee weapon came from when the fight opened, or NULL for bare hands. A record, not a foreign key.');
            $table->json('state')->comment('The whole fight: App\Combat\Fight\FightState::toArray() — both sides, the range, the round, whose turn, the log. Plain data, rebuilt every request.');
            $table->string('result', 20)->nullable()->comment('How it ended: an App\Combat\Fight\Result case. NULL while it is live.');
            $table->timestamp('resolved_at')->nullable()->comment('When it ended. NULL means live — the same test characters.current_fight_id makes from the other side.');
            $table->timestamps();
        });

        Schema::table('characters', function (Blueprint $table): void {
            $table->foreignId('current_fight_id')->nullable()->after('current_hunt_id')
                ->comment('The fight this character is in, or NULL. A column like current_run_id: App\Http\Middleware\EnsureFightIsResolved reads it on every request. Written only by App\Combat\Fights.')
                ->constrained('fights')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('current_fight_id');
        });

        Schema::dropIfExists('fights');
    }
};
