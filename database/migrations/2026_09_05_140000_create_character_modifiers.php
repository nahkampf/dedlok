<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a given character actually has.
 *
 * The stat system has been able to APPLY a modifier since the resolver was
 * written; what it has never been able to do is hold one that belongs to a
 * particular person. Archetype perks and running programs are both derived
 * from something else the character has, so both are computed on every read.
 * This is the other kind: a thing that happened TO somebody and stayed.
 *
 * docs/MECHANICS.md is the contract. The row names a DEFINITION rather than
 * carrying arithmetic, because the arithmetic is mechanics and mechanics live
 * in config — `config/effects.php` for causes with no row of their own, and a
 * `modifiers` JSON column for anything that has one.
 *
 * `data` is what makes one instance different from another of the same
 * definition: a rolled quality, a remaining charge, or — for the accumulating
 * injuries — the magnitude itself. Neural damage is one row per character that
 * deepens; -5 taking another -3 becomes -8 rather than a second row.
 *
 * `source_type` / `source_id` say what CAUSED it, and are null for the injuries
 * that outlive their cause. Everything else exists because of something and
 * should die with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_modifiers', function (Blueprint $table): void {
            $table->comment('What a character is currently carrying: injuries, drugs, statuses. Each row names a definition rather than holding arithmetic, because the arithmetic is mechanics and lives in config.');

            $table->id()->comment('Primary key.');

            $table->foreignId('character_id')
                ->comment('Whose modifier this is.')
                ->constrained()->cascadeOnDelete();

            $table->string('definition_key', 64)
                ->comment('Which definition this is an instance of. PERMANENT: rows reference it, so a definition is retired with a flag and never deleted or renumbered.');

            // Nullable together, and null is a real state rather than missing
            // data: an injury outlives whatever caused it, and the cause is not
            // part of what it is.
            $table->string('source_type', 32)->nullable()
                ->comment('What caused this, if anything still does. NULL for injuries, which outlive their cause.');
            $table->unsignedBigInteger('source_id')->nullable()
                ->comment('The id within source_type. NULL alongside it.');

            $table->unsignedSmallInteger('stacks')->default(1)
                ->comment('How many of this instance are in effect. What that MEANS is the definition stacking rule, not a multiplier anything applies blindly.');

            // NULL is permanent. The overwhelmingly common case, so it is the
            // one that costs nothing to express.
            $table->timestamp('expires_at')->nullable()
                ->comment('When it stops applying. NULL is permanent, which is the ordinary case. Expired rows are filtered on read, not trusted to have been swept.');

            $table->json('data')->nullable()
                ->comment('Per-instance values the definition does not fix: a rolled quality, a remaining charge, or the magnitude of an accumulating injury.');

            $table->timestamps();

            // The hot read: every stat resolution asks for one character's
            // modifiers, and it asks constantly.
            $table->index('character_id');

            // The sweep, and the read filter.
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_modifiers');
    }
};
