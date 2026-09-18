<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Stats\BreakdownStep;

/**
 * Why leaving is as likely as it is.
 *
 * ONE ANSWER, ASSEMBLED ONCE. The screen shows the chance on a button and
 * explains it in a card, the roll spends it, and a test asserts it — all four
 * read this, so none of them can hold a different opinion about the same
 * attempt. `Simulator::egressChance()` is a field of this rather than a second
 * calculation.
 *
 * It carries the WORKING and not only the result, because a number that
 * collapses without saying why is indistinguishable from a bug. Three layers,
 * in the order they happen:
 *
 *  1. the POOL — the attributes, summed, over their divisor;
 *  2. the MODIFIERS — `steps`, straight off `ResolvedStat`, which has carried
 *     "why is my Focus 14?" since the resolver shipped and had nothing
 *     rendering it until this screen;
 *  3. the OPPOSITION — the machine's grade, what is awake in it, and anything
 *     with hold of you.
 *
 * `BreakdownStep` reports one line per LAYER rather than per modifier, because
 * percentages are summed and applied once: listing them separately would imply
 * a compounding that does not happen. The contributing sources are named
 * instead, which is what makes "Egress Protocols" appear on this card without
 * the card knowing that skills exist.
 */
final readonly class EgressReport
{
    /**
     * @param  array<string, float>  $terms  The pool's attributes, resolved.
     * @param  list<BreakdownStep>  $steps  What the modifiers did, one line per layer.
     */
    public function __construct(
        public float $value,
        public float $base,
        public array $terms,
        public float $divisor,
        public array $steps,
        public int $difficulty,
        public int $gradeDifficulty,
        public ?int $grade,
        public ?int $iceLevel,
        public ?int $snareLevel,
        public float $multiplier,
        public float $chance,
    ) {}

    /** The chance as whole per cent, which is the only form a screen ever wants. */
    public function percent(): int
    {
        return (int) round($this->chance * 100);
    }

    /** Is something holding you? */
    public function isSnared(): bool
    {
        return $this->snareLevel !== null;
    }

    /** What the snare costs, in points of chance, for saying out loud. */
    public function snareCost(): int
    {
        if (! $this->isSnared() || $this->multiplier <= 0.0) {
            return 0;
        }

        return (int) round(($this->chance / $this->multiplier - $this->chance) * 100);
    }

    /** Did a modifier move the pool at all? */
    public function wasModified(): bool
    {
        return abs($this->value - $this->base) > 0.0001;
    }
}
