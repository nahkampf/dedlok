<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attack tags: what a piece of ICE does while it is FIGHTING you.
 *
 * `ice.defeat_tags` says what happens to somebody it beats. This is the other
 * half, and the distinction is the point: a defeat tag fires once, at the end,
 * on a person who has already lost, while an attack tag is in force for as
 * long as the thing is awake and alive. The first is a consequence; the second
 * is the shape of the fight.
 *
 * ONE VOCABULARY, ONE COLUMN, the same shape the defeat tags already use — a
 * JSON list of `{tag, level}` — so a second attack tag is an enum case, a
 * config table and a line in a grid rather than a migration. NULL and `[]` are
 * one answer, as they are over there: a piece of ICE that merely hits you is
 * the ordinary case and should not have to say so.
 *
 * The first tag is ENSNARE, which multiplies the egress chance of everybody in
 * the run — a quarter off at level I, and rather more than half at level III.
 * It is PASSIVE while the ICE is alive rather than something a landed hit
 * applies: nothing is stored on the run, nothing has to expire, and breaking
 * the guard is what frees you. That makes killing it a way out rather than
 * only a way to stop being hit.
 *
 * Nothing is written here. `cyberspace:tag-ice` authors both lists, because
 * which pieces carry what is content and a migration carrying content is a
 * design opinion nobody can edit afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ice', function (Blueprint $table): void {
            $table->json('attack_tags')->nullable()->after('defeat_tags')
                ->comment('What this does WHILE it fights, as [{tag, level}]. In force as long as it is awake and alive, unlike defeat_tags which fire once on somebody who lost. NULL and [] are one answer: most ICE merely hits you. Written by cyberspace:tag-ice.');
        });
    }

    public function down(): void
    {
        Schema::table('ice', function (Blueprint $table): void {
            $table->dropColumn('attack_tags');
        });
    }
};
