<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every grant invented rather than specified, brought down to scale.
 *
 * The house scale for a modifier is 2-5%, and most of these were written at
 * 8-15%. Percentages SUM and apply once in this system, and one stat is
 * reachable from skills, implants, programs, perks and injuries at the same
 * time — so grants at the old scale stack into a character who walks through
 * content, and there is no room left for the sources that have not been built
 * yet. It is also far easier to raise a number than to take one back from
 * players who already have it.
 *
 * **AND ONE OF THEM WAS BROKEN.** System Profiling granted at levels 2, 4, 6
 * and 8. `skills.max_level` is FIVE, so two of those four could never fire: the
 * skill was documented as -40% at full training and actually gave -20%. It is
 * now -4% at each of the five levels a skill actually has, which is -20% at
 * level 5 and says what it does.
 *
 * Left alone deliberately:
 *
 *  - **The Surveyor Mk II's +10%**, which is a Tier Two program in a catalogue
 *    that will fill up with small-increment variants — the tier is what pays
 *    for it.
 *  - **The flat grants** — Neural Sheathing's +5 maximum SI and Eidetic
 *    Implants' +3 Memory. A flat add against a derived ceiling is a different
 *    kind of number and wants its own pass rather than a percentage rule
 *    applied to it sideways.
 */
return new class extends Migration
{
    /**
     * The grants as they should read, by skill slug.
     *
     * Whole lists rather than edits, because `skills.modifiers` is one
     * document and a half-rewritten one is worse than either version.
     */
    private function grants(): array
    {
        return [
            // -4% at every level a skill actually has.
            'systemprofiling' => $this->perLevel(-4, 'scan_duration'),

            'cyberdecks' => [
                ['level' => 2, 'op' => 'percentage_of_base', 'amount' => 5, 'target' => ['id' => 'deck_storage']],
                ['level' => 4, 'op' => 'percentage_of_base', 'amount' => 3, 'target' => ['id' => 'deck_qflops']],
            ],

            'thermal_management' => [
                ['level' => 1, 'op' => 'percentage_of_base', 'amount' => -3, 'target' => ['id' => 'deck_thermal_load']],
                ['level' => 3, 'op' => 'percentage_of_base', 'amount' => -3, 'target' => ['id' => 'deck_thermal_load']],
                ['level' => 5, 'op' => 'percentage_of_base', 'amount' => 5, 'target' => ['id' => 'deck_cooling']],
            ],

            // The heat comes down with the compute. Overclocking is a TRADE,
            // and halving one side of a trade without the other turns a choice
            // into a mistake.
            'overclocking' => [
                ['level' => 2, 'op' => 'percentage_of_base', 'amount' => 5, 'target' => ['id' => 'deck_qflops']],
                ['level' => 2, 'op' => 'percentage_of_base', 'amount' => 5, 'target' => ['id' => 'deck_thermal_load']],
                ['level' => 5, 'op' => 'percentage_of_base', 'amount' => 4, 'target' => ['id' => 'deck_qflops']],
            ],

            'routing' => [
                ['level' => 2, 'op' => 'percentage_of_base', 'amount' => 5, 'target' => ['id' => 'deck_bandwidth']],
            ],

            'advancedrouting' => [
                ['level' => 1, 'op' => 'percentage_of_base', 'amount' => 5, 'target' => ['id' => 'check.routing']],
                ['level' => 3, 'op' => 'percentage_of_base', 'amount' => 5, 'target' => ['id' => 'deck_bandwidth']],
                ['level' => 5, 'op' => 'percentage_of_base', 'amount' => -4, 'target' => ['id' => 'deck_power_draw']],
            ],

            'advancedsystemprofiling' => [
                ['level' => 1, 'op' => 'percentage_of_base', 'amount' => 5, 'target' => ['id' => 'check.systemprofiling']],
            ],

            'neuralinterfacing' => [
                ['level' => 3, 'op' => 'percentage_of_base', 'amount' => 4, 'target' => ['id' => 'deck_bandwidth']],
            ],
        ];
    }

    /** One grant per level, for a skill that improves steadily rather than in steps. */
    private function perLevel(int $amount, string $target): array
    {
        $grants = [];

        foreach (range(1, (int) config('skills.max_level', 5)) as $level) {
            $grants[] = [
                'level' => $level,
                'op' => 'percentage_of_base',
                'amount' => $amount,
                'target' => ['id' => $target],
            ];
        }

        return $grants;
    }

    public function up(): void
    {
        foreach ($this->grants() as $slug => $grants) {
            DB::table('skills')->where('slug', $slug)->update([
                'modifiers' => json_encode($grants),
                'updated_at' => now(),
            ]);
        }

        // The ORACLE: still the best instrument in the catalogue, by less.
        DB::table('programs')
            ->whereIn('item_id', DB::table('items')->where('slug', 'scanner-oracle')->select('id'))
            ->update([
                'modifiers' => json_encode([
                    ['op' => 'percentage_of_base', 'amount' => 9, 'target' => ['id' => 'check.systemprofiling']],
                    ['op' => 'add', 'amount' => 1, 'target' => ['id' => 'scan_passes']],
                ]),
                'updated_at' => now(),
            ]);
    }

    /**
     * Nothing is put back.
     *
     * The old numbers were wrong — one of them unreachable — and a down() that
     * restored them would be a migration whose whole purpose was to reintroduce
     * a bug. Tuning content is what the editors are for.
     */
    public function down(): void {}
};
