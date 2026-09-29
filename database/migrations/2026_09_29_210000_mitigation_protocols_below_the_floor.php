<?php

declare(strict_types=1);

use App\Models\Skill;
use Illuminate\Database\Migrations\Migration;

/**
 * Mitigation Protocols was uniform +2%/level on `icebreaker_damage` — which
 * FLOORS. An icebreaker's own bite runs 1–20, so 2% of even the strongest
 * (20 × 0.02 = 0.4) needs a full point to show anything, and levels 1–2 did
 * nothing at all, against any icebreaker, ever. A rank 16, 25,000-cred skill
 * that a player could train halfway and feel nothing from.
 *
 * Given the flat-then-percentage shape Attack Vectors already uses for the
 * same reason: `add +1` at levels 1–2, `percentage_of_base` +4/+4/+6 at
 * levels 3–5. Every level now does something for every icebreaker; the flat
 * step is what reaches the weakest ones, the percentage is what makes it
 * matter more at the top of the ladder, where MP alone plus Attack Vectors 5
 * roughly doubles a bare Attack Vectors 5 icebreaker's bite.
 *
 * A data re-tune by slug, the call Attack Vectors's own 0.28.0 re-tune made.
 */
return new class extends Migration
{
    private const SLUG = 'mitigation_protocols';

    private const MODIFIERS = [
        ['op' => 'add', 'level' => 1, 'amount' => 1, 'target' => ['id' => 'icebreaker_damage']],
        ['op' => 'add', 'level' => 2, 'amount' => 1, 'target' => ['id' => 'icebreaker_damage']],
        ['op' => 'percentage_of_base', 'level' => 3, 'amount' => 4, 'target' => ['id' => 'icebreaker_damage']],
        ['op' => 'percentage_of_base', 'level' => 4, 'amount' => 4, 'target' => ['id' => 'icebreaker_damage']],
        ['op' => 'percentage_of_base', 'level' => 5, 'amount' => 6, 'target' => ['id' => 'icebreaker_damage']],
    ];

    /** What it costs to undo, so `down()` is exact rather than a guess. */
    private const PREVIOUS_MODIFIERS = [
        ['op' => 'percentage_of_base', 'level' => 1, 'amount' => 2, 'target' => ['id' => 'icebreaker_damage']],
        ['op' => 'percentage_of_base', 'level' => 2, 'amount' => 2, 'target' => ['id' => 'icebreaker_damage']],
        ['op' => 'percentage_of_base', 'level' => 3, 'amount' => 2, 'target' => ['id' => 'icebreaker_damage']],
        ['op' => 'percentage_of_base', 'level' => 4, 'amount' => 2, 'target' => ['id' => 'icebreaker_damage']],
        ['op' => 'percentage_of_base', 'level' => 5, 'amount' => 2, 'target' => ['id' => 'icebreaker_damage']],
    ];

    public function up(): void
    {
        Skill::query()->where('slug', self::SLUG)->first()?->update(['modifiers' => self::MODIFIERS]);
    }

    public function down(): void
    {
        Skill::query()->where('slug', self::SLUG)->first()?->update(['modifiers' => self::PREVIOUS_MODIFIERS]);
    }
};
