<?php

declare(strict_types=1);

use App\Models\Skill;
use Illuminate\Database\Migrations\Migration;

/**
 * Reactive Firewalling from −2.5% to −5% of base per level.
 *
 * ICE's attack now runs 7–13 instead of 7–57 (see
 * `2026_09_29_190000_ice_hits_on_a_flatter_curve`), and −2.5% of a number that
 * small rounds back to nothing at level 1 against every grade, and does not
 * land at all below ICE VIII until level 3. −5% still rounds away at level 1
 * against the lowest three grades, but every level from 2 up does something
 * everywhere, and the five-level build (−25%) is a real reduction rather than
 * one masked by rounding.
 *
 * A data re-tune by slug, the call Attack Vectors and Noise Seeding made in
 * `2026_09_28_160000_the_new_skills_brought_to_scale`.
 */
return new class extends Migration
{
    private const SLUG = 'reactive_firewalling';

    private const AMOUNT = -5;

    /** What it costs to undo, so `down()` is exact rather than a guess. */
    private const PREVIOUS_AMOUNT = -2.5;

    public function up(): void
    {
        $this->retune(self::AMOUNT);
    }

    public function down(): void
    {
        $this->retune(self::PREVIOUS_AMOUNT);
    }

    private function retune(int|float $amount): void
    {
        $skill = Skill::query()->where('slug', self::SLUG)->first();

        if ($skill === null) {
            return;
        }

        $skill->update([
            'modifiers' => array_map(
                static fn (int $level): array => [
                    'op' => 'percentage_of_base',
                    'level' => $level,
                    'amount' => $amount,
                    'target' => ['id' => 'damage.synaptic_integrity.ice'],
                ],
                range(1, 5),
            ),
        ]);
    }
};
