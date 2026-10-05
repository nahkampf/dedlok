<?php

declare(strict_types=1);

namespace App\Combat\Fight;

/**
 * Who acts first, decided once at the start and held for the fight. COMBAT.md, *Who acts first*.
 *
 * In an encounter the enemy always goes first, so that is not decided here: the opening state is
 * built with `first: Side::B`. Player against player is Senses + Perception + Reflex, resolved,
 * the higher first and a tie a coin toss on the injected die — and this is that rule, pure.
 */
final class Initiative
{
    /** @param  callable(): float  $dice */
    public static function highest(float $a, float $b, callable $dice): Side
    {
        if ($a === $b) {
            return $dice() < 0.5 ? Side::A : Side::B;
        }

        return $a > $b ? Side::A : Side::B;
    }
}
