<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Skill imprints are no longer sold on the regular market: shops list them (`city:stock-skills`), runs drop them
 * and loyalty stores pay them out. The code refuses to stock, buy or list one; this clears what was already there.
 *
 * An offer is only a listing, so deleting one puts the imprint back in its seller's stash untouched. A shelf row is
 * the market's own stock and is not a player's.
 */
return new class extends Migration
{
    public function up(): void
    {
        $imprints = DB::table('items')->whereNotNull('use_skill_id')->pluck('id');

        DB::table('market_offers')
            ->whereIn('inventory_item_id', DB::table('inventory_items')->whereIn('item_id', $imprints)->select('id'))
            ->delete();

        DB::table('market_stock')->whereIn('item_id', $imprints)->delete();
    }

    public function down(): void
    {
        // Nothing to put back: a deleted listing and an emptied shelf carry nothing worth restoring.
    }
};
