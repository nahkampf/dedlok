<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Skill grants are a NUDGE, not a swing.
 *
 * The first pass handed out 10% and 15% and, worst of all, a flat +10 maximum
 * Synaptic Integrity — which against a starting pool of 31 is a third of the
 * character. That is not what having a skill should be worth. A skill is an
 * edge; the deck, the implants and the software are where the big numbers
 * belong.
 *
 * **The territory is 2%, and 3% is the top of it.** Several grants across
 * several skills still add up to something a player can feel, because
 * percentages of the same base SUM and apply once — four skills at 2% is 8%,
 * arrived at by having trained four things rather than by one lucky pick.
 *
 * Two notes on the shapes used:
 *
 *  - **Neural Sheathing is now a percentage, not a flat add.** A pool that
 *    grows wants a grant that grows with it: 2% is a point at 31 SI and four
 *    points at 200, and stays an edge at both. A flat +5 is a third of a
 *    starting character and a rounding error to a veteran.
 *  - **Eidetic Implants stays a flat +1**, because 2% of an attribute of 15 is
 *    0.3 and the resolver rounds once at the end — it would grant nothing at
 *    all. One point is the smallest quantum an attribute has, and a grant that
 *    cannot be felt is worse than no grant.
 */
return new class extends Migration
{
    /** @return array<string, list<array<string, mixed>>> */
    private function grants(): array
    {
        return [
            // ---- Systems --------------------------------------------------

            'cyberdecks' => [
                ['level' => 2, 'op' => 'percentage_of_base', 'amount' => 2, 'target' => ['id' => 'deck_storage']],
                ['level' => 4, 'op' => 'percentage_of_base', 'amount' => 2, 'target' => ['id' => 'deck_qflops']],
            ],

            // The clearest case in the catalogue, and still only 4% by the
            // time it is mastered.
            'thermal_management' => [
                ['level' => 1, 'op' => 'percentage_of_base', 'amount' => -2, 'target' => ['id' => 'deck_thermal_load']],
                ['level' => 3, 'op' => 'percentage_of_base', 'amount' => -2, 'target' => ['id' => 'deck_thermal_load']],
                ['level' => 5, 'op' => 'percentage_of_base', 'amount' => 3, 'target' => ['id' => 'deck_cooling']],
            ],

            // Compute bought with heat. The trade is what matters, not the
            // size of either side.
            'overclocking' => [
                ['level' => 2, 'op' => 'percentage_of_base', 'amount' => 3, 'target' => ['id' => 'deck_qflops']],
                ['level' => 2, 'op' => 'percentage_of_base', 'amount' => 2, 'target' => ['id' => 'deck_thermal_load']],
                ['level' => 5, 'op' => 'percentage_of_base', 'amount' => 2, 'target' => ['id' => 'deck_qflops']],
            ],

            'routing' => [
                ['level' => 2, 'op' => 'percentage_of_base', 'amount' => 2, 'target' => ['id' => 'deck_bandwidth']],
            ],

            'advancedrouting' => [
                ['level' => 1, 'op' => 'percentage_of_base', 'amount' => 2, 'target' => ['id' => 'check.routing']],
                ['level' => 3, 'op' => 'percentage_of_base', 'amount' => 3, 'target' => ['id' => 'deck_bandwidth']],
                ['level' => 5, 'op' => 'percentage_of_base', 'amount' => -2, 'target' => ['id' => 'deck_power_draw']],
            ],

            'advancedsystemprofiling' => [
                ['level' => 1, 'op' => 'percentage_of_base', 'amount' => 3, 'target' => ['id' => 'check.systemprofiling']],
            ],

            // ---- Cybernetics ----------------------------------------------

            // A percentage so it stays an edge rather than becoming a rounding
            // error the moment a character's pool grows.
            'neuralsheathing' => [
                ['level' => 2, 'op' => 'percentage_of_base', 'amount' => 2, 'target' => ['id' => 'synaptic_integrity_max']],
                ['level' => 4, 'op' => 'percentage_of_base', 'amount' => 2, 'target' => ['id' => 'synaptic_integrity_max']],
            ],

            // Flat, and the smallest quantum an attribute has: 2% of 15 rounds
            // to nothing.
            'eideticimplants' => [
                ['level' => 3, 'op' => 'add', 'amount' => 1, 'target' => ['id' => 'memory']],
            ],

            'neuralinterfacing' => [
                ['level' => 3, 'op' => 'percentage_of_base', 'amount' => 2, 'target' => ['id' => 'deck_bandwidth']],
            ],
        ];
    }

    public function up(): void
    {
        foreach ($this->grants() as $slug => $modifiers) {
            DB::table('skills')->where('slug', $slug)->update([
                'modifiers' => json_encode($modifiers),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Rolling back leaves the smaller numbers in place rather than restoring
     * the larger ones. This migration is a re-tune of authored content, and
     * putting a third of a character's Synaptic Integrity back on a skill is
     * not something a rollback should do quietly.
     */
    public function down(): void
    {
        // Deliberately empty. See above.
    }
};
