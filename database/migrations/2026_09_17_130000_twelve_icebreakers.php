<?php

declare(strict_types=1);

use App\Programs\ProgramKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Twelve icebreakers, and the five ICE integrity values that had to move to
 * make room for them.
 *
 * By migration for the reason the scanners were: content a mechanic cannot work
 * without has to exist before the mechanic ships, and nothing sells one yet. An
 * icebreaker is a GATE as much as a number — no amount of skill adds up to
 * having one — so without these the fight is not merely hard, it is unreachable.
 *
 * ## The rule the damage is set by
 *
 * **Twenty turns is never enough.** Every icebreaker does strictly less than
 * would break its matching ICE in twenty turns, which leaves the whole gap for
 * skills to close later. That is a deliberate floor on fight length and not a
 * balance accident.
 *
 * ## Why five ICE grew
 *
 * Damage has to ascend by at least a point per tier — an icebreaker that costs
 * more and demands more for the same damage is not a better icebreaker. Integer
 * damage of N against a twenty-turn floor needs `integrity > 20N`, and ICE II
 * to VI did not clear that bar: 40, 52, 68, 88 and 112 against 40, 60, 80, 100
 * and 120. They become 46, 68, 90, 112 and 134, which is the smallest change
 * that admits an ascending ladder. The top six are untouched.
 *
 * ## What this makes true, and it is worth saying plainly
 *
 * **A bare character cannot beat even the weakest ICE.** Icebreaker I needs
 * thirty turns against ICE I, and ICE I deals about 1.05 Synaptic Integrity a
 * turn — 31.5 against a starting pool of 31. So fighting is gated behind
 * training rather than being something a new runner does badly: the first
 * icebreaker is a tool for machines that have no ICE, and choosing to fight is
 * an investment decision. That is the intended reading of *the bottom of the
 * grid is walkable* — walkable, not fightable.
 */
return new class extends Migration
{
    /** [level, damage, qflops, memory, storage, cost] */
    private const BREAKERS = [
        [1, 1, 3, 2, 1, 500],
        [2, 2, 4, 3, 1, 1200],
        [3, 3, 6, 4, 2, 2500],
        [4, 4, 9, 5, 2, 5000],
        [5, 5, 12, 7, 3, 9000],
        [6, 6, 16, 9, 3, 16000],
        [7, 7, 21, 12, 4, 28000],
        [8, 8, 27, 15, 4, 48000],
        [9, 11, 34, 19, 5, 80000],
        [10, 13, 42, 24, 6, 130000],
        [11, 16, 52, 30, 7, 210000],
        [12, 20, 64, 38, 8, 340000],
    ];

    /** The five that had to grow: level => integrity. */
    private const ICE_INTEGRITY = [2 => 46, 3 => 68, 4 => 90, 5 => 112, 6 => 134];

    private const NUMERALS = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];

    public function up(): void
    {
        foreach (self::ICE_INTEGRITY as $level => $integrity) {
            DB::table('ice')->where('level', $level)->update(['integrity' => $integrity]);
        }

        $now = now();

        foreach (self::BREAKERS as [$level, $damage, $qflops, $memory, $storage, $cost]) {
            $numeral = self::NUMERALS[$level - 1];

            $itemId = DB::table('items')->insertGetId([
                'slug' => 'icebreaker-'.$level,
                'name' => 'Icebreaker '.$numeral,
                'flavor' => 'Cuts at whatever is in the way, one pass at a time.',
                'container' => 'data',
                // Two steps, like the imprints: the bottom third is Tier One
                // and the rest is Tier Two. Tier is what a thing IS and carries
                // nothing mechanical.
                'tier' => $level <= 4 ? 1 : 2,
                'rarity' => match (true) {
                    $level <= 3 => 'common',
                    $level <= 6 => 'uncommon',
                    $level <= 9 => 'rare',
                    default => 'ultra_rare',
                },
                'base_cost' => $cost,
                // Listed but not stocked, exactly as the scanners are: nothing
                // sells anything yet, and a shelf size invented before the shop
                // would shift every pinned die in the market's own tests.
                'on_market' => true,
                'seed_quantity' => 0,
                'stackable' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('programs')->insert([
                'item_id' => $itemId,
                'kind' => ProgramKind::Icebreaker->value,
                'qflops_cost' => $qflops,
                'memory_cost' => $memory,
                'storage_size' => $storage,
                // AN ICEBREAKER HAS NOTHING TO BREAK OUTSIDE A RUN, which is
                // what `background_process` false means: it is started BY the
                // simulator and never by a player pressing a button on the
                // open grid.
                'background_process' => false,
                // What it cuts for. A modifier rather than a column because
                // everything a program is worth travels this way — so a skill
                // that sharpens an icebreaker and a better icebreaker are the
                // same kind of thing to the resolver.
                'modifiers' => json_encode([[
                    'op' => 'add',
                    'amount' => $damage,
                    'target' => ['id' => 'icebreaker_damage'],
                ]]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        foreach ([2 => 40, 3 => 52, 4 => 68, 5 => 88, 6 => 112] as $level => $integrity) {
            DB::table('ice')->where('level', $level)->update(['integrity' => $integrity]);
        }

        $slugs = array_map(
            static fn (array $row): string => 'icebreaker-'.$row[0],
            self::BREAKERS,
        );

        $ids = DB::table('items')->whereIn('slug', $slugs)->pluck('id');

        DB::table('programs')->whereIn('item_id', $ids)->delete();
        DB::table('items')->whereIn('id', $ids)->delete();
    }
};
