<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A hunter, as durable state — the same shape a run needed for the same reason.
 *
 * A HUNT IS AN ICE, ON THE FLY. `ice_id` points at a real row from the twelve
 * in the catalogue rather than inventing new numbers: a hunter's grade decides
 * which LEVELS it may draw from (`cyberspace.heat.hunt.grades`), and the row
 * picked is snapshotted here exactly as `runs.ice_id`/`ice_integrity` already
 * snapshot one for a system fight. That is what makes every existing ICE
 * resistance — Reactive Firewalling, Defensive Compartmentalization, Spectral —
 * and Ensnare reach a hunter for free: the damage is
 * `damage.synaptic_integrity.ice`, because it is that damage.
 *
 * `grade` IS NOT THE ICE'S OWN LEVEL. It is the hunter's own difficulty band
 * (1–5, from the heat that spawned it), kept alongside the ICE it happened to
 * draw so creds and the heat reduction on a win can be priced by it without
 * re-deriving a band from a level a grade 3 and a grade 4 hunter might share.
 *
 * `characters.current_hunt_id` is what says a character IS in one — a column
 * rather than a flag on this table, the same call `current_run_id` and
 * `equipped_deck_id` make: one column holds one value, so "at most one" is not
 * a rule anybody has to enforce.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hunts', function (Blueprint $table): void {
            $table->comment('A hunter chasing one character. Durable for the reason runs are: the fight has to survive a disconnect, a crash and a deploy, and there is nowhere to escape to.');

            $table->id()->comment('Primary key. Written into characters.current_hunt_id while the hunt is live.');

            $table->foreignId('character_id')
                ->comment('Who is being hunted. Cascades: a deleted character takes their hunts with them.')
                ->constrained()->cascadeOnDelete();

            $table->foreignId('ice_id')
                ->comment('Which real ICE this hunter turned out to be, drawn at random from the level range its grade allows. Authored content, so this is a real foreign key rather than a snapshot of one.')
                ->constrained('ice');

            $table->unsignedSmallInteger('ice_integrity')
                ->comment('What is left of it. Starts at the drawn ICE row\'s own integrity and counts down as the fight is fought.');

            $table->unsignedTinyInteger('grade')
                ->comment('The hunter\'s own difficulty band, 1-5, from the heat that spawned it — not the ICE\'s level, which is drawn from a RANGE the grade allows and may repeat across grades. Prices the creds reward and the heat this fight is worth on a win.');

            $table->decimal('heat_at_spawn', 8, 1)
                ->comment('The character\'s heat at the moment this hunter appeared. A record, not a live figure: heat keeps moving while the fight is fought.');

            $table->timestamp('started_at')->comment('When the hunter appeared.');

            $table->timestamp('resolved_at')->nullable()
                ->comment('When the fight ended, win or lose. NULL means it is still live — the same test current_hunt_id makes from the other side.');

            $table->boolean('won')->nullable()
                ->comment('NULL while live. True or false once resolved_at is set; never both null and resolved.');

            $table->json('log')->nullable()
                ->comment('What happened, exchange by exchange, as the screen replays it. NULL on a hunt that has not resolved one yet.');

            $table->timestamps();
        });

        Schema::table('characters', function (Blueprint $table): void {
            $table->foreignId('current_hunt_id')->nullable()->after('current_run_id')
                ->comment('The hunter this character is fighting, or NULL. A COLUMN rather than a flag on hunts, the same call current_run_id makes — one column holds one value, so "at most one" is not a rule anybody enforces.')
                ->constrained('hunts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->dropForeign(['current_hunt_id']);
            $table->dropColumn('current_hunt_id');
        });

        Schema::dropIfExists('hunts');
    }
};
