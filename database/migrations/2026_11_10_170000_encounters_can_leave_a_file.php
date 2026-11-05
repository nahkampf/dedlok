<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The `file` outcome: a text (or a catalogue item) left on the runner's deck, sealed or not. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('encounter_outcomes', function (Blueprint $table): void {
            $table->mediumText('body')->nullable()->after('entry_text')
                ->comment('For kind file: the text, with {placeholders}, up to 65000 characters. NULL when the file is a catalogue item (item_id) or for any other kind.');
            $table->boolean('ice')->default(false)->after('body')
                ->comment('For kind file: draw the text with iCE colours (a bright background in place of blink), as characters.bio_ice does.');
        });

        Schema::table('character_encounters', function (Blueprint $table): void {
            $table->json('door')->nullable()->after('run_id')
                ->comment('What the door knew when it opened, for placeholders: the system (name, address, type, grade, owner) and the run (purpose, outcome). NULL for a City door. A snapshot, because a burn deletes the system.');
        });

        Schema::create('file_texts', function (Blueprint $table): void {
            $table->comment('The words of a text file an encounter left on somebody. One row per inventory row, snapshotted when it was left: the placeholders are already filled.');

            $table->id()->comment('Primary key.');
            $table->foreignId('inventory_item_id')->unique()->comment('The file. Cascades: destroy, sell or lose the row and the words go with it.')
                ->constrained('inventory_items')->cascadeOnDelete();
            $table->string('title', 120)->comment('What the file is called.');
            $table->mediumText('body')->comment('The text, up to 65000 characters, ANSI escapes allowed (App\\Support\\Ansi draws it).');
            $table->boolean('ice')->default(false)->comment('Draw it with iCE colours.');
            $table->timestamps();
        });

        $now = now();

        DB::table('items')->insertOrIgnore([
            'slug' => 'text-file',
            'name' => 'Extracted data',
            'container' => 'data',
            'tier' => 1,
            'rarity' => 'common',
            'base_cost' => 0,
            'on_market' => false,
            'seed_quantity' => 0,
            'stackable' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('items')->where('slug', 'text-file')->delete();

        Schema::dropIfExists('file_texts');
        Schema::table('character_encounters', fn (Blueprint $t) => $t->dropColumn('door'));
        Schema::table('encounter_outcomes', fn (Blueprint $t) => $t->dropColumn(['body', 'ice']));
    }
};
