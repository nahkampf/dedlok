<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which archetypes a new character may actually be.
 *
 * Seven roles were written down before any of them had a loadout, a perk set
 * or a reason to feel different from the next one. Offering all seven while
 * only one of them is finished is a menu of six dead ends, so this is the
 * switch that says which are ready — and it turns six of them off.
 *
 * A column rather than a deletion, and never a deletion: `characters.archetype`
 * is a foreign key, so an archetype that is retired still has to resolve for
 * everyone who already chose it. Same shape as `items.on_market`, and for the
 * same reason: unlisting is how content is withdrawn here.
 *
 * The default is FALSE, following `items.seed_quantity` — nothing reaches a
 * player by being forgotten. An archetype is offered when someone says so.
 */
return new class extends Migration
{
    /** The one archetype with a role that is actually built out. */
    private const PLAYABLE = ['deckjockey'];

    public function up(): void
    {
        Schema::table('archetypes', function (Blueprint $table) {
            $table->boolean('playable')->default(false)->after('starting_creds');
        });

        DB::table('archetypes')->whereIn('slug', self::PLAYABLE)->update(['playable' => true]);
    }

    public function down(): void
    {
        // Dropping the column puts every archetype back on the menu, which is
        // exactly what "before this migration" meant.
        Schema::table('archetypes', function (Blueprint $table) {
            $table->dropColumn('playable');
        });
    }
};
