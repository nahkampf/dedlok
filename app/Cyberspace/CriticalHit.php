<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\Character;
use Closure;

/**
 * Whether a player's icebreaker hit is critical: one die per hit that lands, against the resolved
 * `critical_chance_cyberspace` (percent, to a tenth). Shared by the run's Attack and a hunter fight so the
 * chance and the multiplier have one home.
 */
final class CriticalHit
{
    /** @param  Closure(int, int): int  $roll  the realm's die, 1..max inclusive */
    public static function rolls(Character $character, Closure $roll): bool
    {
        $tenths = (int) round($character->stat('critical_chance_cyberspace')->value * 10);

        return $tenths > 0 && $roll(1, 1000) <= $tenths;
    }

    /** What a critical hit does to the damage. */
    public static function scale(int $damage): int
    {
        return $damage * (int) config('cyberspace.critical.multiplier', 5);
    }
}
