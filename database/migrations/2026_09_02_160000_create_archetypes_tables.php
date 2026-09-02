<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Archetypes, out of config and into rows.
 *
 * They were the last piece of content defined in a PHP file while the thing
 * that referenced them — `characters.archetype` — was a column. That column
 * held an id pointing into an array, which is a foreign key that nothing could
 * enforce: a typo in config orphaned live character rows silently, and the
 * only thing standing between the roster and a renumbering accident was a
 * comment saying "ids are permanent".
 *
 * They are permanent because rows reference them, so this preserves them
 * exactly and inserts them here rather than in a seeder. Reference data whose
 * ids are load-bearing has to exist in every environment the moment the schema
 * does, including a freshly migrated test database.
 *
 * A perk is a row of its own because an archetype has many, and because the
 * two shapes a perk can take — live, with modifiers the resolver acts on, and
 * display-only, with a number and a unit — are a column-level distinction the
 * database can police.
 */
return new class extends Migration
{
    /**
     * The roster as config/archetypes.php held it when it was deleted.
     *
     * Inlined on purpose: a migration is historical and must keep working
     * after the file it replaces is gone.
     */
    private const ROSTER = [
        1 => ['deckjockey', 'Deck Jockey', 'Hacking', 'You go where the data is and take it. Ice is a puzzle until it is a coffin.'],
        2 => ['crim', 'Crim', 'Combat', 'Raised by the streets, you learned to hustle young. You take what you can get away with, and fight when you have to.'],
        3 => ['sleuth', 'Sleuth', '???', 'tbd'],
        4 => ['codemonkey', 'Code Monkey', 'Code', 'You\'ve done your time as a corporate cubicle rat, mass producing software on the production line. Now you make your buck by writing cutting edge custom software.'],
        5 => ['fixer', 'Fixer', 'Contacts', 'You know a guy. You always know a guy. The city runs on favours and you hold the ledger.'],
        6 => ['hacktivist', 'Hacktivist', 'Data', 'Information wants to be free. You\'re the one to sift through stolen data dumps to extract and decrypt the secrets of the Powers That Be.'],
        7 => ['bloodhound', 'Bloodhound', 'Combat/Scanning', 'Whether a clients wants someone found, a trail followed or a system scoped out, you\'re the one to get the job done.'],
    ];

    public function up(): void
    {
        Schema::create('archetypes', function (Blueprint $table) {
            // No auto-increment: ids are assigned by hand and permanent,
            // because characters.archetype has been storing them since before
            // this table existed. Retire an archetype, never renumber one.
            $table->unsignedTinyInteger('id')->primary();

            $table->string('slug', 40)->unique();
            $table->string('name');
            $table->string('role', 60);
            $table->text('description');

            $table->timestamps();
        });

        Schema::create('archetype_perks', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('archetype_id');
            $table->foreign('archetype_id')->references('id')->on('archetypes')->cascadeOnDelete();

            // Names the perk, not the stat. Unique per archetype so the same
            // perk cannot be attached twice.
            $table->string('key', 40);

            /*
             * A LIVE perk carries modifiers the resolver acts on, and its card
             * text is generated from them, so display cannot drift from
             * behaviour. JSON because a modifier's target is itself a shape
             * ({id} or {all_of: [...]}), and Modifier::fromArray already reads
             * exactly this.
             */
            $table->json('modifiers')->nullable();

            /*
             * A DISPLAY-ONLY perk is the older shape: a number and a unit that
             * render on the card and change nothing. Kept so the shape stays
             * documented and covered while perks are still being written.
             */
            $table->smallInteger('display_modifier')->nullable();
            $table->string('display_unit', 12)->nullable();

            // Null when the label is borrowed: a perk whose unit is 'attribute'
            // takes the attribute's own name, so renaming "Reflex" is one edit.
            $table->string('label')->nullable();

            $table->unsignedTinyInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['archetype_id', 'key']);
        });

        $now = now();

        DB::table('archetypes')->insert(array_map(
            fn (int $id, array $row): array => [
                'id' => $id,
                'slug' => $row[0],
                'name' => $row[1],
                'role' => $row[2],
                'description' => $row[3],
                'created_at' => $now,
                'updated_at' => $now,
            ],
            array_keys(self::ROSTER),
            self::ROSTER,
        ));

        DB::table('archetype_perks')->insert([
            [
                'archetype_id' => 1, 'key' => 'grey_matter', 'label' => 'Grey matter',
                'modifiers' => json_encode([
                    ['op' => 'percentage_of_base', 'amount' => 5, 'target' => ['id' => 'synaptic_integrity_max']],
                ]),
                'display_modifier' => null, 'display_unit' => null,
                'position' => 1, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'archetype_id' => 2, 'key' => 'scar_tissue', 'label' => 'Scar tissue',
                'modifiers' => json_encode([
                    ['op' => 'percentage_of_base', 'amount' => 5, 'target' => ['id' => 'hitpoints_max']],
                ]),
                'display_modifier' => null, 'display_unit' => null,
                'position' => 1, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'archetype_id' => 2, 'key' => 'damage_taken', 'label' => 'Damage taken',
                'modifiers' => null,
                'display_modifier' => -15, 'display_unit' => 'percent',
                'position' => 2, 'created_at' => $now, 'updated_at' => $now,
            ],
        ]);

        /*
         * characters.archetype has always been a foreign key in everything but
         * name. Any value that does not name a row was already broken — it
         * resolved to no archetype and granted no perks — so clearing it costs
         * nothing real and lets the constraint go on.
         */
        DB::table('characters')
            ->whereNotNull('archetype')
            ->whereNotIn('archetype', array_keys(self::ROSTER))
            ->update(['archetype' => null]);

        Schema::table('characters', function (Blueprint $table) {
            $table->foreign('archetype')->references('id')->on('archetypes')->nullOnDelete();
        });

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Live or display-only, never both and never neither.
        DB::statement(
            'ALTER TABLE `archetype_perks` ADD CONSTRAINT `chk_perk_shape` CHECK ('.
            '(`modifiers` IS NOT NULL AND `display_modifier` IS NULL AND `display_unit` IS NULL) OR '.
            '(`modifiers` IS NULL AND `display_modifier` IS NOT NULL AND `display_unit` IS NOT NULL))'
        );
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropForeign(['archetype']);
        });

        Schema::dropIfExists('archetype_perks');
        Schema::dropIfExists('archetypes');
    }
};
