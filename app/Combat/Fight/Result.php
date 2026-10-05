<?php

declare(strict_types=1);

namespace App\Combat\Fight;

/** How a fight ended. NULL on a state means it has not. */
enum Result: string
{
    /** Side A is at zero in either resource. */
    case ADown = 'a_down';

    case BDown = 'b_down';

    /** Side A got away. The fight ends with nothing lost and nothing gained by either. */
    case AEscaped = 'a_escaped';

    case BEscaped = 'b_escaped';

    /** The side that won, or null when nobody did: an escape is not a win for the one left behind. */
    public function winner(): ?Side
    {
        return match ($this) {
            self::ADown => Side::B,
            self::BDown => Side::A,
            self::AEscaped, self::BEscaped => null,
        };
    }

    /** The side that got away, if this was an escape. */
    public function escaped(): ?Side
    {
        return match ($this) {
            self::AEscaped => Side::A,
            self::BEscaped => Side::B,
            default => null,
        };
    }
}
