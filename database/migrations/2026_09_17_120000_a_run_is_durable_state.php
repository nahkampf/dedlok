<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An intrusion run, as a thing that survives being walked away from.
 *
 * A RUN USED TO NEED NO ROW AT ALL. It was atomic — resolved end to end inside
 * one request, with the client replaying a log of something already finished —
 * and this table is the cost of making it interactive. See CYBERSPACE.md, *The
 * run plan*: what atomicity bought was that pulling the plug could not save you,
 * and what buys that now is durable state plus a lock.
 *
 * THE ADDRESS AND NOT THE SYSTEM ID, the rule `system_scans` and
 * `system_contacts` already follow: a run has to be able to outlive the machine
 * it is against, because the machine being burned or relocated out from under it
 * is exactly the case that cancels one. A foreign key would turn that into an
 * integrity error instead of a game event.
 *
 * `characters.current_run_id` is what says a character is IN one — a column
 * rather than a flag on this table, so "only one at a time" is not a rule
 * anybody enforces: one column holds one value. The same call
 * `characters.equipped_deck_id` makes, and for the same reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runs', function (Blueprint $table): void {
            $table->comment('An intrusion run. Durable because a run is interactive: it has to survive a disconnect, a crash and a deploy, which an atomic run never did.');

            $table->id()->comment('Primary key. Written into characters.current_run_id while the run is live.');

            $table->foreignId('character_id')
                ->comment('Whose run. Cascades: a deleted character takes their runs with them, since a run is an event in one life and means nothing without it.')
                ->constrained()->cascadeOnDelete();

            $table->char('address', 8)
                ->comment('The machine being run, as an ADDRESS and never a foreign key — a run must outlive its target, because a machine burned or relocated under it is what cancels one.');

            $table->unsignedSmallInteger('x')->comment('Where the machine stood when the run began. A record, not a pointer: the machine may have moved since.');
            $table->unsignedSmallInteger('y')->comment('The other half of where it stood.');

            $table->string('purpose', 16)
                ->comment('Why they went in: extraction, backdoor or burn. Chosen before the run and never changed — it decides what the turns are for. Mapping is NOT one: the probe took that job.');

            $table->unsignedInteger('turn')->default(0)
                ->comment('How many turns have resolved. Nothing caps it: what stops a run running forever is the deck cooking, which is a better brake than a number.');

            $table->boolean('os_locked')->default(false)
                ->comment('Did the runner lack the operating system? Decided when the run began and NEVER SHOWN — every action against the system fails and the failures are indistinguishable from bad luck, because a refusal would hand over a scan fact for free.');

            $table->timestamp('started_at')->comment('When they went in.');

            $table->timestamp('ended_at')->nullable()
                ->comment('When it finished. NULL means live, and a live run is what the lock sends every other request back to.');

            $table->string('ending', 24)->nullable()
                ->comment('How it finished: egress, defeat, burnout, cancelled. NULL while it is still going. Cancelled is the machine going away under them, which is nobody losing.');

            $table->json('log')->nullable()
                ->comment('What happened, turn by turn, as the client replays it. NULL on a run that has not resolved a turn yet.');

            $table->timestamps();
        });

        $this->comment('runs', 'created_at', 'When this row was written.');
        $this->comment('runs', 'updated_at', 'When this row was last changed.');

        Schema::table('characters', function (Blueprint $table): void {
            $table->foreignId('current_run_id')->nullable()->after('grid_y')
                ->comment('The run this character is inside, or NULL. A COLUMN rather than a flag on runs, so "one at a time" is not a rule anybody enforces — one column holds one value, the same call equipped_deck_id makes.')
                ->constrained('runs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->dropForeign(['current_run_id']);
            $table->dropColumn('current_run_id');
        });

        Schema::dropIfExists('runs');
    }

    /** timestamps() returns void, so its comments need a statement of their own. */
    private function comment(string $table, string $column, string $comment): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE `%s` MODIFY `%s` TIMESTAMP NULL DEFAULT NULL COMMENT %s',
            $table,
            $column,
            DB::getPdo()->quote($comment),
        ));
    }
};
