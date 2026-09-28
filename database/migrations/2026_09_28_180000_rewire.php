<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * REWIRE, the first thing in the game that heals Synaptic Integrity for sale.
 *
 * Nothing else heals it — Neuroplasticity is a rank 16 book — so a runner who
 * lost a fight had no way back. Healing is `synaptic_regen` for the length of a
 * dose, which `characters:regenerate` credits every minute like any other
 * source: no new mechanic, only a row.
 *
 * THE NUMBERS, and why:
 *
 *   +2 SI a minute for 10 minutes   20 SI a dose, about two-thirds of a
 *                                   starting pool. Two per minute rather than
 *                                   one because regen FLOORS: tolerance
 *                                   scales one point down to 0.9, which floors
 *                                   to nothing, while two scales to one.
 *   tolerance 10 a dose             the first band (25) on the third dose, so
 *                                   a heavy user heals at half the rate for
 *                                   days. Tolerance scales only the good half.
 *   addictive 0                     nobody gets hooked.
 *   −8% Intellect and Focus         the price, for as long as it runs, and
 *                                   never softened by tolerance.
 *
 * Arrives by migration like the icebreakers did, so it reaches production on
 * deploy; tune it in the item editor afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $itemId = DB::table('items')->insertGetId([
            'slug' => 'rewire',
            'name' => 'Rewire',
            'flavor' => 'A proprietary mix of synthetic designer nootropics that stimulates neuron health, myelin sheath formation, axonal vitality and reduces the effects of hypoxic damage.',
            'container' => 'physical',
            'tier' => 1,
            'rarity' => 'common',
            'base_cost' => 500,
            'on_market' => true,
            'seed_quantity' => 20,
            'stackable' => true,
            'modifiers' => json_encode([
                ['op' => 'add', 'amount' => 2, 'target' => ['id' => 'synaptic_regen']],
                ['op' => 'percentage_of_base', 'amount' => -8, 'target' => ['id' => 'intellect']],
                ['op' => 'percentage_of_base', 'amount' => -8, 'target' => ['id' => 'focus']],
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('drugs')->insert([
            'item_id' => $itemId,
            'family' => 'nootropic',
            'duration_minutes' => 10,
            'tolerance_gain' => 10,
            'addictive' => 0,
            'lethal_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $id = DB::table('items')->where('slug', 'rewire')->value('id');

        if ($id !== null) {
            DB::table('drugs')->where('item_id', $id)->delete();
            DB::table('items')->where('id', $id)->delete();
        }
    }
};
