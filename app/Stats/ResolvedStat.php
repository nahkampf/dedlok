<?php

declare(strict_types=1);

namespace App\Stats;

/**
 * The outcome of resolving one stat.
 *
 * Both numbers are deliberate:
 *
 *   raw    unclamped and unrounded, and therefore possibly negative or above
 *          the ceiling. Diagnostics, and any maths that chains further.
 *   value  clamped and rounded. THIS is what game logic uses.
 *
 * Clamping late is what keeps the Add / PercentageOfBase / Multiplier layers
 * commutative: clamping at 0 mid-pipeline would make "-20 then +5" differ from
 * "+5 then -20".
 */
final readonly class ResolvedStat
{
    /** @param  list<BreakdownStep>  $steps */
    public function __construct(
        public string $statId,
        public float $base,
        public float $raw,
        public float $value,
        public array $steps = [],
    ) {}

    public function wasClamped(): bool
    {
        return abs($this->raw - $this->value) > 0.000001
            && ($this->raw < $this->value || $this->raw > $this->value);
    }

    public function toInt(): int
    {
        return (int) round($this->value);
    }
}
