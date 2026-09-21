<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backdoors: keeping access to a machine you have already been inside.
 *
 * A BACKDOOR POINTS AT THE SYSTEM, not at an address — and that is the
 * opposite call from `system_contacts`, `system_scans` and `runs`, which all
 * carry an address precisely so they can outlive the machine. The difference
 * is what the row IS. Those three are recollections, and a memory of a machine
 * that has gone is still a memory. A backdoor is ACCESS, and access to a
 * machine that has gone is nothing at all — so it cascades away with it, and
 * `backdoors.system_id` is a real foreign key.
 *
 * It is also what makes the design's own promise true: a backdoored system
 * does not lock down and does not relocate, so its address is permanent in a
 * world where nothing else is. The address is read off the system it points
 * at, never copied, because a copy is a thing that can disagree.
 *
 * ONE PER CHARACTER PER MACHINE, by unique index. Two people may each hold one
 * on the same system and neither knows about the other.
 *
 * THE KIND IS CHOSEN AT INSTALL AND CANNOT BE CHANGED. A system siphons or it
 * routes, never both, and changing your mind means going back and doing the
 * run again. That asymmetry is the whole design: a siphon is safe and slow, a
 * routing hop is free and expendable, and a trace that catches you takes every
 * routing backdoor in the chain and none of your siphons.
 *
 * `runs.backdoored` is the other half, and it is `runs.extracted`'s shape for
 * the same reason: a backdoor run that SUCCEEDED leaves no lockdown behind,
 * because the install erases the logs and resets what it tripped. One that
 * failed cleans up nothing and locks the machine down like any other run, so
 * the flag has to record what actually happened rather than what was intended.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backdoors', function (Blueprint $table): void {
            $table->comment('Access somebody keeps on a machine they have already been inside. One per character per system; the kind is chosen at install and is permanent.');

            $table->id()->comment('Surrogate key.');

            // The comment must come BEFORE constrained(), which returns the
            // foreign key rather than the column — see CLAUDE.md.
            $table->foreignId('character_id')
                ->comment('Who holds it. Cascades: a deleted character keeps nothing.')
                ->constrained()->cascadeOnDelete();

            $table->foreignId('system_id')
                ->comment('The machine it opens. A real foreign key rather than an address, because a backdoor is ACCESS and not a memory: access to a machine that has gone is nothing at all. Cascades.')
                ->constrained()->cascadeOnDelete();

            $table->string('kind', 16)
                ->comment('siphon (a little cash nightly) or routing (a hop in the chain a trace must walk). Chosen at install, never changed: changing your mind means doing the run again.');

            $table->timestamp('installed_at')
                ->comment('When it went in. The nightly ageing roll reads it: the odds of being discovered rise with age and with nothing else.');

            // Spelled out rather than `timestamps()`, which returns void and
            // so cannot be commented inline — see CLAUDE.md. Both are
            // bookkeeping and mean what they mean on every table.
            $table->timestamp('created_at')->nullable()->comment('When the row was written.');
            $table->timestamp('updated_at')->nullable()->comment('When the row was last changed.');

            $table->unique(['character_id', 'system_id'], 'uniq_backdoors_character_system');
            $table->index(['character_id', 'kind'], 'idx_backdoors_character_kind');
        });

        // SQLite installs no CHECK constraints in this project; MySQL is where
        // the guarantee lives, the same split every other table here takes.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::getConnection()->statement(
                "ALTER TABLE backdoors ADD CONSTRAINT chk_backdoors_kind CHECK (kind IN ('siphon', 'routing'))"
            );
        }

        Schema::table('runs', function (Blueprint $table): void {
            $table->boolean('backdoored')->default(false)->after('extracted')
                ->comment('Did this run actually install a backdoor? A successful install leaves no lockdown behind — it erases the logs and resets what it tripped — and a failed one cleans up nothing, so this records what happened rather than what was intended.');

            $table->string('backdoor_kind', 16)->nullable()->after('backdoored')
                ->comment('Which kind this run means to install: siphon, routing, or NULL on every run that is not a backdoor run. Chosen at the door with the purpose and never changed, because what the machine is worth is something you decide from the map rather than mid-fight.');
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table): void {
            $table->dropColumn('backdoored');
        });

        Schema::dropIfExists('backdoors');
    }
};
