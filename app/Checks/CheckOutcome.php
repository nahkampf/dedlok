<?php

declare(strict_types=1);

namespace App\Checks;

/**
 * What a roll came to, and everything needed to explain it afterwards.
 *
 * The chance is kept alongside the verdict on purpose. A log that says *failed*
 * teaches nothing; one that says *failed, 73%* teaches a player that the plan
 * was good and the night was not, which is the difference between a game that
 * feels arbitrary and one that feels hard. It is also what a balance pass reads.
 */
final readonly class CheckOutcome
{
    public function __construct(
        public bool $passed,
        public float $value,
        public float $difficulty,
        public float $chance,
        public float $rolled,
        public ?string $kind = null,
    ) {}

    /** How far the character was over or under the opposition. */
    public function margin(): float
    {
        return $this->value - $this->difficulty;
    }

    /**
     * Did the floor decide this?
     *
     * True when the roll landed in the sliver of probability the certainty
     * floor keeps open at either end — the household IDS that caught the best
     * runner alive. Worth knowing, because "you were unlucky" and "you were
     * outmatched" are different stories and only one of them is a reason to
     * change the plan.
     */
    public function wasFloored(float $floor): bool
    {
        return $this->passed
            ? $this->chance <= $floor
            : $this->chance >= 1 - $floor;
    }
}
