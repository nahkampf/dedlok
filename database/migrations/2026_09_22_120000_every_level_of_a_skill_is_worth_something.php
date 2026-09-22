<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every level of a skill is worth something.
 *
 * The first pass made levels MILESTONES — one grant at 2, another at 5 — and
 * the skills authored since grant at every level instead. Seven skills were
 * still on milestones, which read on the skill card as levels that do nothing.
 * They move to the per-level shape here, at amounts chosen by hand.
 *
 * Thermal Management loses its level-5 cooling grant in the process: it is now
 * one thing, less heat, at every level.
 */
return new class extends Migration
{
    /** @return array<string, list<array<string, mixed>>> */
    private function grants(): array
    {
        return [
            'cyberdecks' => $this->perLevel('percentage_of_base', 4, 'deck_storage'),
            'neuralsheathing' => $this->perLevel('percentage_of_base', 1, 'synaptic_integrity_max'),
            // Still a trade: the heat climbs faster than the compute does.
            'overclocking' => [
                ...$this->perLevel('percentage_of_base', 3, 'deck_qflops'),
                ...$this->perLevel('percentage_of_base', 4, 'deck_thermal_load'),
            ],
            'thermal_management' => $this->perLevel('percentage_of_base', -2, 'deck_thermal_load'),
            'neuralinterfacing' => $this->perLevel('percentage_of_base', 3, 'deck_bandwidth'),
            // Flat, as before: 1% of an attribute of 15 rounds to nothing.
            'eideticimplants' => $this->perLevel('add', 1, 'memory'),
            'advancedsystemprofiling' => $this->perLevel('percentage_of_base', 3, 'check.systemprofiling'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function perLevel(string $op, int $amount, string $target): array
    {
        $grants = [];

        foreach (range(1, (int) config('skills.max_level', 5)) as $level) {
            $grants[] = ['level' => $level, 'op' => $op, 'amount' => $amount, 'target' => ['id' => $target]];
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
    }

    /**
     * A re-tune of authored content, like the two before it: rolling back
     * leaves these numbers in place rather than restoring the milestones.
     */
    public function down(): void {}
};
