<?php

declare(strict_types=1);

namespace App\Combat\Fight;

/**
 * One of the two sides of a fight. In an encounter A is the player and B the opponent; player
 * against player is symmetric, which is why they are not called that.
 */
enum Side: string
{
    case A = 'a';
    case B = 'b';

    public function other(): self
    {
        return $this === self::A ? self::B : self::A;
    }
}
