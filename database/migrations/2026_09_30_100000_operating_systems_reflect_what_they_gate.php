<?php

declare(strict_types=1);

use App\Models\Skill;
use Illuminate\Database\Migrations\Migration;

/**
 * The four operating-system skills, repriced against what they actually
 * unlock, and each given a small bonus toward hacking any operating system —
 * without touching the gate.
 *
 * REPRICED. AuraOS and Prism-V cost the same (5,000) despite AuraOS gating
 * 1,880 of the grid's 2,900 systems (grades 1–3) against Prism-V's 639
 * (grades 4–5); IRONCLAD gates 289 (grades 6–7) at 15,000; Lattice gates 58
 * (grades 8–9, ultra-rare) at 250,000. The new prices track both rank and
 * how much of the grid each one opens, rather than only one of the two:
 * 3,000 / 8,000 / 20,000 / 200,000.
 *
 * EACH SKILL NOW GRANTS +1% of base per level (5% trained out) on the whole
 * `check+operating-systems` tag — the same tag Kernel Exploitation targets —
 * so training deeply on one OS teaches a little that carries to the others,
 * the way Kernel Exploitation already does at a larger scale.
 *
 * THE GATE IS UNTOUCHED, by construction rather than by a rule anybody has to
 * remember: `percentage_of_base` scales what is already there, and an
 * untrained OS's table value is 0 — a percentage of nothing is nothing. A
 * character with AuraOS 5 and no Lattice still opens a Lattice-gated core at
 * exactly the base level 0 gives, whatever these four skills grant.
 * `Simulator::osValue()` is unchanged.
 */
return new class extends Migration
{
    /** slug => [new base_cost, new modifier amount per level] */
    private const SKILLS = [
        'auroos' => 3000,
        'prismv' => 8000,
        'ironclad' => 20000,
        'lattice' => 200000,
    ];

    /** What it costs to undo, so `down()` is exact rather than a guess. */
    private const PREVIOUS_COSTS = [
        'auroos' => 5000,
        'prismv' => 5000,
        'ironclad' => 15000,
        'lattice' => 250000,
    ];

    private const AMOUNT = 1;

    public function up(): void
    {
        foreach (self::SKILLS as $slug => $cost) {
            Skill::query()->where('slug', $slug)->first()?->update([
                'base_cost' => $cost,
                'modifiers' => array_map(
                    static fn (int $level): array => [
                        'op' => 'percentage_of_base',
                        'level' => $level,
                        'amount' => self::AMOUNT,
                        'target' => ['all_of' => ['check', 'operating-systems']],
                    ],
                    range(1, 5),
                ),
            ]);
        }
    }

    public function down(): void
    {
        foreach (self::PREVIOUS_COSTS as $slug => $cost) {
            Skill::query()->where('slug', $slug)->first()?->update([
                'base_cost' => $cost,
                'modifiers' => null,
            ]);
        }
    }
};
