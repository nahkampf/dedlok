<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Close Quarter Combat, Neural Sheathing and Efficient Cooling were free or near it (0, 0 and 0 on the skill; 300,
 * 0 and 900 on the imprint) and now have a price. Both are set, since the skill's `base_cost` and its imprint's are
 * kept in step on every save and the imprint's is the one a shop charges.
 */
return new class extends Migration
{
    private const PRICES = [
        'closequartercombat' => 500,
        'neuralsheathing' => 2000,
        'efficient_cooling' => 1000,
    ];

    public function up(): void
    {
        foreach (self::PRICES as $slug => $price) {
            $id = DB::table('skills')->where('slug', $slug)->value('id');

            if ($id === null) {
                continue;
            }

            DB::table('skills')->where('id', $id)->update(['base_cost' => $price]);
            DB::table('items')->where('use_skill_id', $id)->update(['base_cost' => $price]);
        }
    }

    public function down(): void
    {
        // The old figures were placeholders; not worth restoring.
    }
};
