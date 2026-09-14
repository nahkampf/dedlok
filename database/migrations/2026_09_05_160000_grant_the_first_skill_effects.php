<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The first pass at what having a skill is worth.
 *
 * NINE SKILLS OF TWENTY-SIX GET SOMETHING, and the seventeen that do not are
 * the honest part of this migration rather than an oversight. A grant has to
 * move a number that exists: the deck measures exist, maximum SI and the
 * attributes exist, and every skill's own `check.*` exists. Combat, intrusion,
 * scanning and decryption do not — so Blades, IRONCLAD, BLACKGLASS and
 * Leadership have nothing true to grant, and inventing "+2 Intellect for
 * Leadership" would be filler that says nothing about leadership and would
 * have to be unpicked when the real thing arrives.
 *
 * The numbers are a FIRST PASS and are meant to be tuned. What they are chosen
 * to demonstrate is shape rather than balance:
 *
 *  - **Levels are milestones.** A grant applies from its level upward, so a
 *    skill hands out one thing at 2 and another at 5 rather than everything at
 *    once.
 *  - **Percentages sum and apply once**, which is the resolver's rule, so two
 *    -5% grants on one stat are -10% and never -9.75%.
 *  - **A grant may cost something.** Overclocking buys compute with heat, in
 *    one skill, which is the shape a skill tree wants — a choice rather than a
 *    reward.
 *  - **An advanced skill may improve the basic one.** Advanced Routing raising
 *    `check.routing` is a real relationship and not circular, which is why the
 *    OS ladder gets nothing here: those skills gate what you may attempt, and
 *    gating is not a modifier.
 *
 * By migration rather than by a seeder, because the skill rows themselves
 * arrived that way — there is no SkillSeeder — and because a seeder holding
 * this would be a second source for content the table owns.
 */
return new class extends Migration
{
    /**
     * Each entry applies from its `level` upward.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function grants(): array
    {
        return [
            // ---- Systems: the skills about the machine you carry ----------

            // Knowing decks: more room for software, and a little more out of
            // the processor you have.
            'cyberdecks' => [
                ['level' => 2, 'op' => 'percentage_of_base', 'amount' => 10, 'target' => ['id' => 'deck_storage']],
                ['level' => 4, 'op' => 'percentage_of_base', 'amount' => 5, 'target' => ['id' => 'deck_qflops']],
            ],

            // The thermal skill, and the clearest case in the catalogue: the
            // deck runs cooler because of who is running it.
            'thermal_management' => [
                ['level' => 1, 'op' => 'percentage_of_base', 'amount' => -5, 'target' => ['id' => 'deck_thermal_load']],
                ['level' => 3, 'op' => 'percentage_of_base', 'amount' => -5, 'target' => ['id' => 'deck_thermal_load']],
                ['level' => 5, 'op' => 'percentage_of_base', 'amount' => 10, 'target' => ['id' => 'deck_cooling']],
            ],

            // Compute bought with heat. A choice rather than a reward, and the
            // reason a player might NOT train this.
            'overclocking' => [
                ['level' => 2, 'op' => 'percentage_of_base', 'amount' => 10, 'target' => ['id' => 'deck_qflops']],
                ['level' => 2, 'op' => 'percentage_of_base', 'amount' => 8, 'target' => ['id' => 'deck_thermal_load']],
                ['level' => 5, 'op' => 'percentage_of_base', 'amount' => 8, 'target' => ['id' => 'deck_qflops']],
            ],

            'routing' => [
                ['level' => 2, 'op' => 'percentage_of_base', 'amount' => 10, 'target' => ['id' => 'deck_bandwidth']],
            ],

            // The advanced skill makes the basic one better, and eventually
            // pays for itself in power.
            'advancedrouting' => [
                ['level' => 1, 'op' => 'percentage_of_base', 'amount' => 10, 'target' => ['id' => 'check.routing']],
                ['level' => 3, 'op' => 'percentage_of_base', 'amount' => 15, 'target' => ['id' => 'deck_bandwidth']],
                ['level' => 5, 'op' => 'percentage_of_base', 'amount' => -10, 'target' => ['id' => 'deck_power_draw']],
            ],

            // Same relationship, on the profiling pair. Nothing reads a scan
            // yet, but the check it improves is real today.
            'advancedsystemprofiling' => [
                ['level' => 1, 'op' => 'percentage_of_base', 'amount' => 15, 'target' => ['id' => 'check.systemprofiling']],
            ],

            // ---- Cybernetics: the skills about the body ------------------

            // Shielding the nervous system is the one thing in the catalogue
            // that directly answers what ICE does to a person.
            'neuralsheathing' => [
                ['level' => 2, 'op' => 'add', 'amount' => 5, 'target' => ['id' => 'synaptic_integrity_max']],
                ['level' => 4, 'op' => 'add', 'amount' => 5, 'target' => ['id' => 'synaptic_integrity_max']],
            ],

            'eideticimplants' => [
                ['level' => 3, 'op' => 'add', 'amount' => 3, 'target' => ['id' => 'memory']],
            ],

            // The deck reaches you faster because the interface is better
            // tuned to you.
            'neuralinterfacing' => [
                ['level' => 3, 'op' => 'percentage_of_base', 'amount' => 8, 'target' => ['id' => 'deck_bandwidth']],
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

    public function down(): void
    {
        DB::table('skills')->whereIn('slug', array_keys($this->grants()))->update([
            'modifiers' => null,
            'updated_at' => now(),
        ]);
    }
};
