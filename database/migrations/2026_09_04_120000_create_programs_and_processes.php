<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Software: the first items in the game that change a number.
 *
 * An item is a program because it HAS a `programs` row, exactly as an item is
 * deck hardware because it has a `deck_modules` row. No new column on `items`
 * says so — a row that carries costs and a kind is what being a program means,
 * and one place to look is better than two that can disagree.
 *
 * WHAT A PROGRAM COSTS, and why these three are not one number:
 *
 *   qflops_cost   compute, spent only while it RUNS. The cap on how much can
 *                 be going at once.
 *   memory_cost   quantabits, likewise spent while running. A second cap on
 *                 the same set, and deliberately not the same one: a deck can
 *                 be full without being busy and busy without being full.
 *   storage_size  what it occupies just by being owned. Paid whether it runs
 *                 or not, and the reason storage is a separate measure.
 *
 * THERE IS NO "LOADED". A program is running or it is not. Running splits into
 * background — a decryption grinding away while the owner is signed out — and
 * active, which only means during a run. `background_process` says which a
 * program is ALLOWED to be: an icebreaker is meaningless outside a run, and a
 * decryptor is meaningless inside one.
 *
 * RUNNING IS TWO COLUMNS ON THE INVENTORY ROW, not a join table. A deck fitting
 * needs three references because a socket is a place and only the pair
 * (deck, slot) names one. A process has no socket — a program runs on a deck,
 * full stop — so a join table would buy nothing and cost a source of truth.
 *
 * It also has to be on the row for a reason that is not aesthetic:
 * `InventoryItem::stackKey()` is derived from the row on every save, and the
 * stacking rule is that IDLE copies pile up and RUNNING ones do not. A running
 * flag in another table is invisible to that derivation, and two running
 * icebreakers would silently merge into one row with a quantity of two — which
 * is precisely the bug the stack_key rewrite existed to kill for cyberdecks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programs', function (Blueprint $table): void {
            $table->id();

            // One program per item, and the item is what carries name, flavour,
            // tier, rarity and price. This side carries only what an item row
            // cannot say.
            $table->foreignId('item_id')->unique()->constrained()->cascadeOnDelete();

            // What it is FOR. Programs are gates as well as modifiers — you
            // cannot fight ICE without a running icebreaker — so the kind is
            // asked about by name and is not decoration.
            $table->string('kind', 32);

            // Spent while running.
            $table->unsignedInteger('qflops_cost')->default(0);
            $table->unsignedInteger('memory_cost')->default(0);

            // Paid by owning it at all.
            $table->unsignedInteger('storage_size')->default(0);

            /*
             * May it run outside a cyberspace run? A decryptor must — that is
             * the whole of what it does. An icebreaker must not: there is
             * nothing for it to break outside a run, and a deck quietly
             * spending compute on one is a deck that will not start the run
             * the player actually wanted.
             */
            $table->boolean('background_process')->default(false);

            /*
             * What running it DOES to the character's stats, in the shape
             * App\Stats\Modifier::fromArray() reads — the same JSON archetype
             * perks already use, so the resolver learns nothing new.
             *
             * Nullable rather than an empty array, because a program that only
             * gates (a plain icebreaker) genuinely modifies nothing, and "[]"
             * and "nothing" should not be two ways of writing one fact.
             */
            $table->json('modifiers')->nullable();

            $table->timestamps();
        });

        Schema::table('inventory_items', function (Blueprint $table): void {
            /*
             * The deck this row is running on, and when it started.
             *
             * Null is the ordinary state: owned, idle, stacked with its
             * siblings. Non-null takes the row out of the stash the way a
             * fitting or an equip does — see InventoryItem::scopeLoose(), which
             * now decides FOUR ways of being spoken for in one place.
             *
             * RESTRICT, deliberately, and it is the one decision here that was
             * forced by the database rather than chosen freely.
             *
             * ON DELETE SET NULL was the first attempt and MySQL refused the
             * CHECK constraints below with error 3823: a column carrying a
             * referential action may not appear in one. It was right to refuse
             * — setting the deck id to null would have left `started_at`
             * behind, which is precisely the state the "both or neither" check
             * exists to forbid. The two rules were in conflict, not merely
             * incompatible in syntax.
             *
             * CASCADE was never an option: losing a machine must not delete the
             * software that was running on it.
             *
             * So RESTRICT, and destroying a deck has to stop its processes
             * first — the same shape `items.use_skill_id` already uses for a
             * skill and its book, where the database refuses and the controller
             * clears the dependant inside the same transaction. The convention
             * becomes a guarantee.
             */
            $table->foreignId('running_on_deck_id')
                ->nullable()
                ->after('parent_id')
                ->constrained('inventory_items');

            // A process has a start time because a decryption cycle is measured
            // from one. Null exactly when running_on_deck_id is.
            $table->timestamp('started_at', 6)->nullable()->after('running_on_deck_id');

            $table->index(['running_on_deck_id'], 'idx_inventory_items_running_on');
        });

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // A running row has both halves or neither. The pair is one fact.
        DB::statement(
            'ALTER TABLE `inventory_items` ADD CONSTRAINT `chk_inventory_items_running_pair` CHECK ('.
            '(`running_on_deck_id` IS NULL AND `started_at` IS NULL) OR '.
            '(`running_on_deck_id` IS NOT NULL AND `started_at` IS NOT NULL))'
        );

        /*
         * "A row cannot run on itself" is NOT here, and cannot be.
         *
         * It would have to mention `id`, and `id` is the target of two
         * cascading foreign keys on this same table — so MySQL refuses it with
         * the same error 3823 that killed ON DELETE SET NULL above. There is no
         * arrangement of this table that both cascades a container's contents
         * and checks a column against its own primary key.
         *
         * It is also the least valuable of the three: a program running on
         * itself is not a thing ProgramRunner can produce, and nothing else
         * writes these columns.
         */

        // The kinds App\Programs\ProgramKind knows. A fifteenth is an enum case
        // and a line here, the same shape items.hardware already has.
        DB::statement(
            'ALTER TABLE `programs` ADD CONSTRAINT `chk_programs_kind` CHECK ('.
            "`kind` IN ('icebreaker','decryptor','evasion','analyser','daemon'))"
        );

        /*
         * `background_process` is NOT constrained against the kind, and that is
         * deliberate. An icebreaker running outside a run is useless, but
         * "useless" is a design opinion rather than an invariant, and content
         * lives in the database precisely so an author can make an odd thing on
         * purpose. ProgramKind::runsInBackgroundByDefault() is what the editor
         * offers; the author is what decides.
         */
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `inventory_items` DROP CONSTRAINT `chk_inventory_items_running_pair`');
        }

        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->dropIndex('idx_inventory_items_running_on');
            $table->dropConstrainedForeignId('running_on_deck_id');
            $table->dropColumn('started_at');
        });

        Schema::dropIfExists('programs');
    }
};
