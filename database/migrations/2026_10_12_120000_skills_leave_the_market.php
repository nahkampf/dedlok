<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Skill imprints are no longer on the market's own shelf: shops list them (`city:stock-skills`), runs drop them and
 * loyalty stores pay them out. The code refuses to stock or sell one from the shelf; this clears what was there.
 *
 * Only the shelf, which is the market's own stock. Players may still sell imprints to one another, so their
 * offers are left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        $imprints = DB::table('items')->whereNotNull('use_skill_id')->pluck('id');

        DB::table('market_stock')->whereIn('item_id', $imprints)->delete();
    }

    public function down(): void
    {
        // Nothing to put back: an emptied shelf carries nothing worth restoring.
    }
};
