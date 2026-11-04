<?php

declare(strict_types=1);

namespace App\Combat;

use App\Support\Points;

/**
 * What one hit does, before resistance: the damage the weapon (and its round) resolved to, scaled
 * by the tier it landed on, split across damage types by the profile. docs/COMBAT.md, steps 3 and 4.
 *
 * Each part is `amount × share / 100` to two decimals and a part that comes to nothing is dropped, so
 * the parts can sum to a hundredth more or less than the whole and the sum is what lands. A part goes to
 * `DamageResolver` as its own event under its own type. Pure.
 */
final class HitDamage
{
    /**
     * @param  float  $base  the resolved damage of the weapon with this round, unrounded
     * @param  array<string, int>  $profile  damage type => percent share, summing to 100
     * @return array<string, int> damage type => amount, in the profile's order
     */
    public static function parts(float $base, HitQuality $tier, array $profile): array
    {
        $amount = $base * $tier->fraction();
        $parts = [];

        foreach ($profile as $type => $share) {
            $part = Points::of($amount * $share / 100);

            if ($part > 0) {
                $parts[$type] = $part;
            }
        }

        return $parts;
    }
}
