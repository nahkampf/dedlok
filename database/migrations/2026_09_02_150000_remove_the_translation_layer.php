<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the schema owes to translation being gone.
 *
 * Two things, and they point in opposite directions.
 *
 * An item's name and flavour used to be looked up from lang/en/items.php by
 * slug, because a row could not hold a word that had to exist in every
 * language. There is one language now, so the row holds it: the definition is
 * one thing again instead of a row plus an entry someone has to remember.
 * Both are nullable because an imprint has neither — there is exactly one
 * book per skill, so its name is still composed from the skill it teaches.
 *
 * And `users.locale` recorded which language a player wanted, which is no
 * longer a question that can be asked.
 */
return new class extends Migration
{
    /**
     * The strings as lang/en/items.php held them at the moment it was deleted.
     * Inlined rather than read from config: a migration is historical, and this
     * one has to keep working long after the file it rescues is gone.
     */
    private const NAMES = [
        'sandevistan_mk5' => ['Sandevistan MK.V', 'Pulled from a Militech cadaver. Still warm when they found it.'],
        'kiroshi_optics' => ['Kiroshi Optics', 'Sees more than you want it to.'],
        'ballistic_coprocessor' => ['Ballistic Coprocessor', 'Does the maths so your hands do not have to.'],
        'memory_shard' => ['Memory Shard', 'Sixty-four gig of nothing until you put something on it.'],
        'scrap_alloy' => ['Scrap Alloy', 'Worth almost nothing. Almost.'],
    ];

    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            // Nullable: an imprint's name composes from its skill, and an item
            // with no flavour is the common case rather than an omission.
            $table->string('name')->nullable()->after('slug');
            $table->text('flavor')->nullable()->after('name');
        });

        foreach (self::NAMES as $slug => [$name, $flavor]) {
            DB::table('items')->where('slug', $slug)->update([
                'name' => $name,
                'flavor' => $flavor,
            ]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Whatever anyone had chosen is not coming back; the column is.
            $table->string('locale', 8)->nullable();
        });

        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['name', 'flavor']);
        });
    }
};
