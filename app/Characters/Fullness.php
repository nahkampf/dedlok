<?php

declare(strict_types=1);

namespace App\Characters;

use App\Models\Character;
use App\Support\Points;

/**
 * Fullness: a hidden bar from nothing to a fixed maximum (`fatigue.fullness.max`). Eating fills it, the minute clock
 * drains it, and being full only means nothing more can be eaten. The only writer of `characters.current_fullness`.
 * FATIGUE.md, *Food and fullness*.
 */
final class Fullness
{
    public function maximum(): int|float
    {
        return Points::of((float) config('fatigue.fullness.max'));
    }

    public function isFull(Character $character): bool
    {
        return (float) $character->current_fullness >= (float) $this->maximum();
    }

    /** Fill it, never past the maximum. Returns the points actually added. */
    public function add(Character $character, int|float $points): int|float
    {
        return $this->move($character, abs($points));
    }

    /** Empty it, never below nothing. Returns the points actually removed. */
    public function drain(Character $character, int|float $points): int|float
    {
        return -$this->move($character, -abs($points));
    }

    private function move(Character $character, int|float $delta): int|float
    {
        $before = (float) $character->current_fullness;
        $after = max(0.0, min((float) $this->maximum(), $before + $delta));

        $character->forceFill(['current_fullness' => Points::of($after)])->save();

        return Points::of(abs($after - $before));
    }
}
