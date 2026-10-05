<?php

declare(strict_types=1);

namespace App\Combat;

/**
 * Recoil. COMBAT.md, *Burst and automatic fire*.
 *
 * A weapon's `rate` is how many shots one Attack fires, and each is its own attack. The first uses
 * the full chance; every later shot in the same action is `burst_falloff` (7%) worse than the one
 * before, compounding, to stand for recoil and the difficulty of keeping aim on automatic. The
 * result is handed to `CheckRoller::chance()` as its `multiplier`, which applies it to the raw
 * curve and then applies the certainty floor again — so a long burst falls to the floor and no
 * further. The count restarts with every action.
 */
final class Burst
{
    /** The factor on the chance of shot `$shot`, the first being 0. */
    public static function multiplier(int $shot): float
    {
        if ($shot < 0) {
            throw new \InvalidArgumentException('A burst starts at shot zero.');
        }

        return (1.0 - (float) config('combat.burst_falloff')) ** $shot;
    }

    /** How many shots an action fires: the weapon's rate, or what is left in the magazine. */
    public static function shots(int $rate, int $rounds): int
    {
        return max(0, min($rate, $rounds));
    }
}
