<?php

declare(strict_types=1);

namespace App\Combat\Fight;

/**
 * Which weapon a distance lets you use. The distance itself is a number on `FightState`: any distance above
 * nought is Ranged, and nought is Close. COMBAT.md.
 */
enum Range: string
{
    case Ranged = 'ranged';
    case Close = 'close';

    public static function at(int $distance): self
    {
        return $distance > 0 ? self::Ranged : self::Close;
    }
}
