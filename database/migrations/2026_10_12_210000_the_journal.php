<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The journal on the Tasks & Missions screen: notes (headings) and the lines beneath them, written by encounters.
 *
 * A note is a level 0 heading with a slug; a line is a row under one, with a slug of its own. Both carry a
 * `completed` flag: a completed note is not shown, and a completed line is shown struck through. Newest first, so
 * the order is the id and nothing else is stored for it.
 *
 * Encounter outcomes gain the three columns the two journal kinds need: the heading, and the line's slug and text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_notes', function (Blueprint $table): void {
            $table->comment('A level 0 note in a character\'s journal: a heading with a slug. Written only by App\\Journal\\Journal.');

            $table->id()->comment('Primary key. The order notes are listed in, newest first.');
            $table->foreignId('character_id')->comment('Whose journal. Cascades with the character.')->constrained()->cascadeOnDelete();
            $table->string('slug', 60)->comment('Stable name an encounter outcome refers to. Unique per character.');
            $table->string('title', 255)->comment('The heading, as it was written when the note was made. Player-visible.');
            $table->boolean('completed')->default(false)->comment('True: the note is finished and is not shown.');
            $table->timestamps();

            $table->unique(['character_id', 'slug']);
        });

        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->comment('A line under a journal note, with a slug of its own. Written only by App\\Journal\\Journal.');

            $table->id()->comment('Primary key. The order lines are listed in, newest first.');
            $table->foreignId('character_id')->comment('Whose journal. Cascades with the character.')->constrained()->cascadeOnDelete();
            $table->foreignId('note_id')->comment('The note it sits under. Cascades with it.')->constrained('journal_notes')->cascadeOnDelete();
            $table->string('slug', 60)->comment('Stable name an encounter outcome refers to. Unique per character.');
            $table->string('body', 500)->comment('The line, as it was written. Player-visible.');
            $table->boolean('completed')->default(false)->comment('True: the line is shown struck through.');
            $table->timestamps();

            $table->unique(['character_id', 'slug']);
        });

        Schema::table('encounter_outcomes', function (Blueprint $table): void {
            $table->string('text', 255)->nullable()->after('entity_id')
                ->comment('For kind journal: the note\'s heading, used when the note does not exist yet. NULL otherwise.');
            $table->string('entry_slug', 60)->nullable()->after('text')
                ->comment('For kind journal: the slug of the line to add under the note. NULL adds none.');
            $table->string('entry_text', 500)->nullable()->after('entry_slug')
                ->comment('For kind journal: the text of the line to add. NULL adds none.');
        });
    }

    public function down(): void
    {
        Schema::table('encounter_outcomes', fn (Blueprint $t) => $t->dropColumn(['text', 'entry_slug', 'entry_text']));
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('journal_notes');
    }
};
