<?php

declare(strict_types=1);

namespace App\Combat;

use App\Support\Text;

/**
 * How well a hit landed. docs/COMBAT.md.
 *
 * Five rungs, weakest first, and each is a fraction of the weapon's full damage per hit
 * (`config/combat.php`, `hit_quality`). The weapon's damage is fixed; this is the only thing
 * that scales it down, so "how good a shot was it" and "how hard does the gun hit" are two
 * questions. Which rung a hit gets is the engine's, not this enum's.
 */
enum HitQuality: string
{
    case NearMiss = 'near_miss';
    case Glancing = 'glancing';
    case Partial = 'partial';
    case CleanHit = 'clean_hit';
    case DeadOn = 'dead_on';

    public function label(): string
    {
        return Text::get("combat.quality.{$this->value}");
    }

    /**
     * Which rung a shot landed on, from one die and the chance quoted for it.
     *
     * `v = chance + spread × (0.5 − u)` is cut into five equal bands. So the die moves a shot up or
     * down the ladder from where the chance alone would put it, a certain shot cannot go below
     * Partial at the default spread and a hopeless one cannot reach it, and the average damage of a
     * shot stays close to chance × damage. Pure: the die is somebody else's.
     *
     * @param  float  $u  the die, uniform in [0, 1)
     * @param  float  $chance  from `CheckRoller::chance()`, in [0, 1]
     */
    public static function fromRoll(float $u, float $chance, ?float $spread = null): self
    {
        $spread ??= (float) config('combat.spread');
        $v = min(max($chance + $spread * (0.5 - $u), 0.0), 1.0);

        // Five equal bands; exactly 1.0 belongs to the top one.
        return self::cases()[min(4, (int) floor($v * 5))];
    }

    /** The share of full damage this rung does, from 0 to 1. */
    public function fraction(): float
    {
        return (float) config("combat.hit_quality.{$this->value}");
    }

    /** The damage a hit of `$damage` does at this rung, rounded once at the end. */
    public function scale(int|float $damage): int
    {
        return (int) round($damage * $this->fraction());
    }
}
