<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Narrative encounters: a graph of nodes and choices, outcomes as rows, and the
 * live instance a character is standing in. docs/CITY.md, *Encounters*.
 *
 * NODES ARE REACHED BY KEY, NOT BY ID. `next_key`, `pass_key`, `fail_key` and
 * `character_encounters.node_key` name a node within its encounter. That is what
 * lets the editor replace an encounter's nodes wholesale on every save without
 * stranding somebody halfway through it: the live row carries a string that still
 * means the same node afterwards, or means nothing, which the runner closes
 * gracefully.
 *
 * NO CHECK TOUCHES A FOREIGN-KEY COLUMN. `district_id`, `location_id`, `item_id`
 * and `entity_id` cascade, and MySQL refuses a CHECK on such a column (error 3823)
 * — the suite runs on SQLite and would never say so. "An outcome of kind item has
 * an item" lives in `EncounterRequest` instead, as `entity_offers` does it.
 * What can be a CHECK is, because the columns are plain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('encounters', function (Blueprint $table): void {
            $table->comment('A narrative encounter definition: something that happens to a character in the City. Authored in the admin; no encounter is seeded.');

            $table->id()->comment('Primary key.');
            $table->string('slug', 40)->unique()
                ->comment('Stable machine name, [a-z0-9_]. Permanent. "encounter_<slug>" is the flag set when a `once` encounter is entered.');
            $table->string('title', 150)->comment('What the screen calls it. Player-visible.');
            $table->text('intro')->nullable()->comment('Shown above the first node. Player-visible. NULL is ordinary.');
            $table->string('starts_on', 16)
                ->comment('What can open it: travel (a pass of a trip matured), explore (a pass of exploring matured) or place (a button on a location page).');
            $table->unsignedSmallInteger('weight')->default(10)
                ->comment('Share of the draw, out of max(sum of eligible weights, city.encounter.pool). Small against the pool is RARE: a lone encounter of weight 2 against a pool of 100 is a 2% pick, not a certainty.');

            $table->foreignId('district_id')->nullable()
                ->comment('Only here, for travel and explore. NULL is anywhere. Cascades: a district that goes takes its encounters.')
                ->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()
                ->comment('The place that offers it, for starts_on = place. Cascades with the location.')
                ->constrained()->cascadeOnDelete();

            $table->string('required_flag', 60)->nullable()->comment('A character_flags slug the character must hold. NULL is none.');
            $table->boolean('once')->default(false)->comment('True: set the flag "encounter_<slug>" on entry and never offer it to that character again.');
            $table->boolean('retired')->default(false)->comment('Withdrawn from every draw. Never deleted for being old; a live instance still finishes.');
            $table->timestamps();
        });

        Schema::create('encounter_nodes', function (Blueprint $table): void {
            $table->comment('One screen of an encounter. Replaced wholesale per encounter on every editor save; reached by node_key.');

            $table->id()->comment('Primary key. Never stored anywhere else: everything points at node_key.');
            $table->foreignId('encounter_id')->comment('Owning encounter.')->constrained()->cascadeOnDelete();
            $table->string('node_key', 40)->comment('Name within the encounter, [a-z0-9_]. Unique per encounter.');
            $table->text('body')->comment('What the player reads. Player-visible.');
            $table->boolean('entry')->default(false)->comment('The node an encounter opens on. Exactly one per encounter (held by the request).');
            $table->boolean('ends')->default(false)->comment('True: this node closes the encounter. It shows its body and a single Continue; its choices are ignored.');

            $table->unique(['encounter_id', 'node_key']);
        });

        Schema::create('encounter_choices', function (Blueprint $table): void {
            $table->comment('A button on a node. May cost creds, may be gated on a flag, may roll a check.');

            $table->id()->comment('Primary key. The browser posts this and the runner checks it belongs to the CURRENT node.');
            $table->foreignId('node_id')->comment('The node it is offered on.')->constrained('encounter_nodes')->cascadeOnDelete();
            $table->unsignedTinyInteger('position')->default(0)->comment('Order on the screen, ascending.');
            $table->string('label', 200)->comment('The button. Player-visible.');
            $table->string('requires_flag', 60)->nullable()->comment('Offered only to a character holding this flag. Hidden, never greyed. NULL is always offered.');
            $table->unsignedInteger('cost')->default(0)->comment('Creds charged before any roll, and not refunded by a failure. 0 is free. Unaffordable disables the button.');
            $table->string('next_key', 40)->nullable()->comment('The node to go to. NULL closes the encounter. With a check, the fallback for a pass or fail with no key of its own.');
            $table->string('check_stat', 100)->nullable()->comment('A stat id resolved through the character\'s Sheet and rolled against `difficulty`. NULL means no roll.');
            $table->decimal('difficulty', 5, 1)->nullable()->comment('What check_stat is rolled against, through CheckRoller. NULL exactly when check_stat is.');
            $table->string('pass_key', 40)->nullable()->comment('Node on a passed check; falls back to next_key.');
            $table->string('fail_key', 40)->nullable()->comment('Node on a failed check; falls back to next_key.');
        });

        Schema::create('encounter_outcomes', function (Blueprint $table): void {
            $table->comment('What a choice does, one row per effect. Every kind goes through the writer that already owns it.');

            $table->id()->comment('Primary key.');
            $table->foreignId('choice_id')->comment('The choice that causes it.')->constrained('encounter_choices')->cascadeOnDelete();
            $table->string('branch', 8)->comment('always, pass or fail. pass and fail apply only to a choice with a check.');
            $table->unsignedTinyInteger('position')->default(0)->comment('Order applied, ascending.');
            $table->string('kind', 16)->comment('OutcomeKind: creds, item, standing, heat, flag, injury or damage.');
            $table->decimal('amount', 10, 2)->nullable()->comment('Signed. Creds, a quantity of items, standing points, heat, injury points or damage; meaning by kind. NULL for a flag.');
            $table->string('ref', 100)->nullable()->comment('A flag slug (flag), an injury key from config/effects.php (injury) or hitpoints / synaptic_integrity (damage).');
            $table->foreignId('item_id')->nullable()->comment('For kind item. Cascades: a deleted item removes the outcome.')->constrained()->cascadeOnDelete();
            $table->foreignId('entity_id')->nullable()->comment('For kind standing. Cascades with the entity.')->constrained()->cascadeOnDelete();
        });

        Schema::create('character_encounters', function (Blueprint $table): void {
            $table->comment('The encounter a character is in right now. Deleted when it closes; the flag is the only lasting record.');

            $table->id()->comment('Primary key. Written into characters.current_encounter_id while live.');
            $table->foreignId('character_id')->comment('Who is in it. Cascades.')->constrained()->cascadeOnDelete();
            $table->foreignId('encounter_id')->comment('Which. Cascades: deleting a definition ends it, and the character\'s pointer nulls.')->constrained()->cascadeOnDelete();
            $table->string('node_key', 40)->comment('The node being shown. A key that no longer exists closes the encounter.');
            $table->json('log')->nullable()->comment('What happened choice by choice, as the screen replays it. Append-only.');
            $table->timestamp('started_at')->comment('When it opened.');
            $table->timestamps();
        });

        Schema::table('characters', function (Blueprint $table): void {
            $table->foreignId('current_encounter_id')->nullable()->after('current_hunt_id')
                ->comment('The encounter this character is in, or NULL. A COLUMN like current_run_id: one column holds one value, so "at most one" is not a rule anybody enforces.')
                ->constrained('character_encounters')->nullOnDelete();
        });

        // Plain columns only (see the class comment), so these are safe on MySQL.
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE encounters ADD CONSTRAINT chk_encounters_starts_on CHECK (starts_on IN ('travel','explore','place'))");
            DB::statement('ALTER TABLE encounter_choices ADD CONSTRAINT chk_encounter_choices_check CHECK ((check_stat IS NULL) = (difficulty IS NULL))');
            DB::statement('ALTER TABLE encounter_choices ADD CONSTRAINT chk_encounter_choices_branches CHECK (check_stat IS NOT NULL OR (pass_key IS NULL AND fail_key IS NULL))');
            DB::statement("ALTER TABLE encounter_outcomes ADD CONSTRAINT chk_encounter_outcomes_branch CHECK (branch IN ('always','pass','fail'))");
        }
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->dropForeign(['current_encounter_id']);
            $table->dropColumn('current_encounter_id');
        });

        Schema::dropIfExists('character_encounters');
        Schema::dropIfExists('encounter_outcomes');
        Schema::dropIfExists('encounter_choices');
        Schema::dropIfExists('encounter_nodes');
        Schema::dropIfExists('encounters');
    }
};
