<?php

declare(strict_types=1);

namespace App\Stats;

/**
 * One line of "why is my Focus 14?".
 *
 * A layer produces a single step even when several modifiers fed it, because
 * that is how the arithmetic actually works: all percentages are summed and
 * applied once, so reporting them individually would imply a compounding that
 * does not happen. Contributing sources are listed instead.
 */
final readonly class BreakdownStep
{
    /** @param  list<string>  $sources */
    public function __construct(
        public string $op,
        public float $amount,
        public float $from,
        public float $to,
        public array $sources = [],
    ) {}

    public function delta(): float
    {
        return $this->to - $this->from;
    }
}
