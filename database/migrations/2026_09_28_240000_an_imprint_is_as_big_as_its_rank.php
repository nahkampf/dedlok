<?php

declare(strict_types=1);

use App\Inventory\Imprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every imprint from a flat 8 QB to its skill's rank: 1, 2, 4, 8 or 16.
 *
 * The data inventory lives on the deck now, and at 8 a new Deck Jockey could
 * not buy a single imprint — the Explorer holds 8 QB and their software already
 * takes one. By rank, the quickest skills cost next to nothing to carry and a
 * rank 16 imprint needs a better deck or a storage module first.
 *
 * Only imprints still at the old flat 8 move, so a size somebody set by hand in
 * the editor stays theirs.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ranks = DB::table('skills')->pluck('rank', 'id');

        DB::table('items')->where('use_op', 'inject_skill')->where('size', 8)->get(['id', 'use_skill_id'])
            ->each(fn (object $imprint) => DB::table('items')->where('id', $imprint->id)->update([
                'size' => Imprint::sizeForRank((int) ($ranks[$imprint->use_skill_id] ?? 1)),
            ]));
    }

    public function down(): void
    {
        DB::table('items')->where('use_op', 'inject_skill')->update(['size' => 8]);
    }
};
