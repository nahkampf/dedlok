<?php

declare(strict_types=1);

use App\Models\Skill;
use Illuminate\Database\Migrations\Migration;

/**
 * Neural Sheathing from +1% to +5% of base per level on `synaptic_integrity_max`.
 *
 * SI max FLOORS, and a starting character's SI max sits around 30–40 — 1% of
 * that is under half a point, so levels 1–3 did nothing for a typically-built
 * character, and only levels 4–5 moved it by a single point. +5%/level lands
 * at level 1 even at the lowest legal attribute spread (SI max 20, the whole
 * game's own floor), and is +25% trained out — a real handful of points
 * rather than one.
 *
 * A data re-tune by slug, the call Reactive Firewalling's own re-tune made.
 */
return new class extends Migration
{
    private const SLUG = 'neuralsheathing';

    private const AMOUNT = 5;

    /** What it costs to undo, so `down()` is exact rather than a guess. */
    private const PREVIOUS_AMOUNT = 1;

    public function up(): void
    {
        $this->retune(self::AMOUNT);
    }

    public function down(): void
    {
        $this->retune(self::PREVIOUS_AMOUNT);
    }

    private function retune(int $amount): void
    {
        Skill::query()->where('slug', self::SLUG)->first()?->update([
            'modifiers' => array_map(
                static fn (int $level): array => [
                    'op' => 'percentage_of_base',
                    'level' => $level,
                    'amount' => $amount,
                    'target' => ['id' => 'synaptic_integrity_max'],
                ],
                range(1, 5),
            ),
        ]);
    }
};
