<?php

declare(strict_types=1);

use App\Missions\MissionAction;
use App\Missions\MissionState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Missions: a designated set of systems, an action required of each, a clock.
 *
 * ## Generated per character, never authored and never shared
 *
 * An agent's board is rolled for whoever is standing at the desk, so a
 * mission row belongs to one character from the moment it exists — including
 * the ones only OFFERED. Nobody can take your job, and two players at one desk
 * see different work.
 *
 * ## The rewards are snapshotted at the offer
 *
 * `creds_reward`, `standing_reward` and `loyalty_reward` are written when the
 * job is rolled, not read from `config/missions.php` at hand-in. Retuning the
 * curve must never change a job somebody already took — the same call
 * `market_purchases.unit_price` makes about a price.
 *
 * ## Targets carry the address as well as the id
 *
 * `mission_targets.system_id` is a real foreign key because a target is a
 * machine you must reach, and `address` beside it is what that machine was
 * CALLED when the job was written. The pair is how a void is detected without
 * a sweep: the id resolves to nothing, or it resolves to a row wearing a
 * different address, and either way the job named something that no longer
 * exists. Contacts, scans and runs all make the opposite call and store only
 * the address; a target needs both because it has to be findable AND
 * checkable.
 *
 * IT NULLS RATHER THAN CASCADES, which is the one decision here that is not
 * obvious. A burn deletes a system, and a cascade would take the target row
 * with it — so a job whose targets had all been burned by somebody else would
 * have no outstanding rows left and would read as FINISHED. Nulling keeps the
 * row, keeps the address, and lets `stillStands()` say what actually
 * happened.
 *
 * ## `satisfied_at` is one column filled several ways
 *
 * A scan target is satisfied at the desk, against a file the runner carries
 * in. A burn and a plant are satisfied by the act, in the same transaction
 * that performs it. A new action picks whichever suits and nothing here
 * changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('missions', function (Blueprint $table): void {
            $table->comment('Work an entity is offering one character, or has them doing. Rolled per character per desk and never shared.');

            $table->id()->comment('Surrogate key. mission_targets references it.');

            $table->foreignId('character_id')
                ->comment('Whose job it is. Offered rows belong to somebody too - a board is rolled for the person standing at it.')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('entity_id')
                ->comment('Who is paying. The standing and loyalty land in this entity\'s books.')
                ->constrained('entities')
                ->cascadeOnDelete();

            $table->foreignId('exchange_id')
                ->comment('The desk it was offered at. Its grade is what scaled the rewards and decides which systems may be named.')
                ->constrained('exchanges')
                ->cascadeOnDelete();

            $table->string('action', 20)->comment('An App\Missions\MissionAction case: scan, burn, plant or supply. Decides whether evidence is carried in or recorded where it happens.');
            $table->string('state', 20)->comment('An App\Missions\MissionState case. Offered and accepted are live; completed, failed and voided are endings.');

            $table->unsignedSmallInteger('quantity_required')
                ->default(0)
                ->comment('For supply, how many complete scan files the agent wants. Zero for every action that designates targets instead.');

            $table->unsignedInteger('creds_reward')->comment('What it pays, snapshotted at the offer so retuning config/missions.php cannot change a job already taken.');
            $table->decimal('standing_reward', 6, 3)->comment('The standing BASE, snapshotted at the offer. Resolved through the standing_gain stat at hand-in, so a modifier can move what actually lands.');
            $table->unsignedSmallInteger('loyalty_reward')->comment('Loyalty paid on completion. Never decays and is spent at a store that is not built.');
            $table->decimal('penalty', 6, 3)->comment('What failing or abandoning costs in standing, snapshotted with the rewards. A void costs nothing and does not read this.');

            $table->timestamp('offered_at')->comment('When it was rolled onto the board. What the hourly refresh measures.');
            $table->timestamp('accepted_at')->nullable()->comment('When it was taken. NULL while it is only on offer; the window is measured from here and never from the offer.');
            $table->timestamp('expires_at')->nullable()->comment('When the window closes. Written on ACCEPT rather than at the offer, and NULL until then.');
            $table->timestamp('completed_at')->nullable()->comment('When it ended, however it ended. NULL until the state is one of the three endings.');

            $table->timestamp('created_at')->nullable()->comment('When the row was written.');
            $table->timestamp('updated_at')->nullable()->comment('When the row was last changed.');

            // "What is this character doing for this entity", which is the
            // question the one-job-per-agent cap asks on every accept.
            $table->index(['character_id', 'entity_id', 'state'], 'idx_missions_character_entity_state');

            // The board: what this desk is offering this character right now.
            $table->index(['character_id', 'exchange_id', 'state'], 'idx_missions_board');

            // The expiry sweep, which reads accepted rows past their window.
            $table->index(['state', 'expires_at'], 'idx_missions_expiry');
        });

        Schema::create('mission_targets', function (Blueprint $table): void {
            $table->comment('One row per system a mission designates. Supply missions have none - they ask for a count of files instead.');

            $table->id()->comment('Surrogate key. Nothing outside this table references it.');

            $table->foreignId('mission_id')
                ->comment('The job this target belongs to.')
                ->constrained('missions')
                ->cascadeOnDelete();

            $table->foreignId('system_id')
                ->nullable()
                ->comment('The machine to be scanned, burned or planted on. A real foreign key, unlike a contact or a scan: a target is somewhere you have to GO. NULLED rather than cascaded when the machine is deleted - the row has to survive its own subject, or a job whose targets were all burned by somebody else would read as finished.')
                ->constrained('systems')
                ->nullOnDelete();

            $table->char('address', 8)->comment('What that machine answered to when the job was written. Compared against the live row to spot a relocation: same id, new address, means the job named something that is no longer there.');

            $table->timestamp('satisfied_at')->nullable()->comment('When this target was done. Filled at hand-in for a carried evidence action, and by the act itself for a burn or a plant. NULL is outstanding.');

            $table->timestamp('created_at')->nullable()->comment('When the row was written.');
            $table->timestamp('updated_at')->nullable()->comment('When the row was last changed.');

            // A system is named once per job: two targets pointing at one
            // machine would be a job that could be half done by one act.
            $table->unique(['mission_id', 'system_id'], 'uq_mission_targets_system');
        });

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $actions = "'".implode("','", MissionAction::values())."'";
        $states = "'".implode("','", MissionState::values())."'";

        DB::statement("ALTER TABLE `missions` ADD CONSTRAINT `chk_missions_action` CHECK (`action` IN ({$actions}))");
        DB::statement("ALTER TABLE `missions` ADD CONSTRAINT `chk_missions_state` CHECK (`state` IN ({$states}))");

        /*
         * A taken job has a clock; an offered one has none.
         *
         * The two columns are null together and set together, which is what
         * makes "the window starts on accept" a fact about the schema rather
         * than a rule the writer has to remember. Same shape as
         * `running_on_deck_id` and `started_at` on an inventory row.
         */
        DB::statement(
            'ALTER TABLE `missions` ADD CONSTRAINT `chk_missions_clock` CHECK ('
            .'(`accepted_at` IS NULL AND `expires_at` IS NULL) OR '
            .'(`accepted_at` IS NOT NULL AND `expires_at` IS NOT NULL))'
        );

        // Supply asks for files and names no systems; everything else names
        // systems and asks for nothing. One or the other, never both.
        DB::statement(
            'ALTER TABLE `missions` ADD CONSTRAINT `chk_missions_quantity` CHECK ('
            ."(`action` = 'supply' AND `quantity_required` > 0) OR "
            ."(`action` <> 'supply' AND `quantity_required` = 0))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('mission_targets');
        Schema::dropIfExists('missions');
    }
};
