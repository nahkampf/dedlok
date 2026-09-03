<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What an archetype hands a character on the way in.
 *
 * An archetype was a name, a role and a set of perks: it said who you were and
 * it moved a stat or two. It said nothing about what you START with, which is
 * the half a player actually feels on their first screen — a Deck Jockey who
 * knows how to jack in, a Fixer with money and nothing else.
 *
 * Three things, and only one of them is a column. Cash is a single number and
 * belongs on the row. Skills and items are sets, so they are rows of their
 * own, keyed by the archetype and pointing at the definition — a foreign key
 * the database enforces, rather than a slug in a config file that nothing
 * checks until a character is made.
 *
 * `characters.creds` is the other half of the cash: somewhere for it to land.
 * It is the first money in the game. Nothing spends it yet — the market's
 * supply side exists and its storefront does not — so this is a balance and a
 * grant, and no transaction.
 */
return new class extends Migration
{
    /**
     * The skill level ceiling, as `config('skills.max_level')` held it.
     *
     * Duplicated on purpose, the same way create_skills_tables duplicates it:
     * a migration is a historical record and must not change when the config
     * does. `chk_character_skills_level` is written against the same number.
     */
    private const MAX_LEVEL = 5;

    public function up(): void
    {
        Schema::table('archetypes', function (Blueprint $table) {
            // Unsigned, so "starts in debt" is not expressible. If it ever
            // should be, that is a signed column and a design decision about
            // what a negative balance does, not a default anyone drifts into.
            $table->unsignedInteger('starting_creds')->default(0)->after('description');
        });

        Schema::table('characters', function (Blueprint $table) {
            // Stored, mutable, and unbounded above — which is what separates
            // it from the two resources beside it. Those clamp to a derived
            // maximum; money has no maximum to clamp to.
            $table->unsignedInteger('creds')->default(0)->after('current_synaptic_integrity');
        });

        Schema::create('archetype_skills', function (Blueprint $table) {
            $table->id();

            // tinyInteger to match archetypes.id, which is assigned by hand.
            $table->unsignedTinyInteger('archetype_id');
            $table->foreign('archetype_id')->references('id')->on('archetypes')->cascadeOnDelete();

            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();

            /*
             * The level the character starts at, and 0 is the useful default
             * rather than a missing value: level 0 is a real state — the skill
             * is injected and untrained — and it is what a skillbook buys. A
             * level above 0 is the archetype saying the character already put
             * the months in.
             */
            $table->unsignedTinyInteger('level')->default(0);

            $table->timestamps();

            // One grant per skill. "Hacking at 1 and Hacking at 3" is a data
            // error, not two grants.
            $table->unique(['archetype_id', 'skill_id']);
        });

        Schema::create('archetype_items', function (Blueprint $table) {
            $table->id();

            $table->unsignedTinyInteger('archetype_id');
            $table->foreign('archetype_id')->references('id')->on('archetypes')->cascadeOnDelete();

            $table->foreignId('item_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('quantity')->default(1);

            $table->timestamps();

            $table->unique(['archetype_id', 'item_id']);
        });

        if (DB::getDriverName() !== 'mysql') {
            return;   // SQLite cannot add constraints via ALTER TABLE
        }

        foreach ([
            'archetype_skills' => [
                'chk_archetype_skills_level' => '`level` BETWEEN 0 AND '.self::MAX_LEVEL,
            ],
            'archetype_items' => [
                // A grant of nothing is not a grant. Zero would silently
                // produce an inventory row the database itself refuses.
                'chk_archetype_items_quantity' => '`quantity` >= 1',
            ],
        ] as $table => $constraints) {
            foreach ($constraints as $name => $expression) {
                DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('archetype_items');
        Schema::dropIfExists('archetype_skills');

        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('creds');
        });

        Schema::table('archetypes', function (Blueprint $table) {
            $table->dropColumn('starting_creds');
        });
    }
};
