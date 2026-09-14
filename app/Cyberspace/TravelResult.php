<?php

declare(strict_types=1);

namespace App\Cyberspace;

/**
 * What came of trying to go somewhere.
 *
 * The same shape as FittingResult and PlacementResult: a refusal is a value,
 * not an exception, because every one of them is something a player can
 * reasonably try.
 *
 * An arrival carries the SWEEP it caused, because arriving somewhere and
 * looking around are one act and the screen wants both halves — where you now
 * are, and what that revealed. Jacking out is the one success with neither: it
 * has no position and it sees nothing.
 */
final class TravelResult
{
    private function __construct(
        public readonly ?Position $position,
        public readonly ?SweepResult $sweep,
        public readonly ?TravelRefusal $refusal,
    ) {}

    public static function arrived(Position $position, SweepResult $sweep): self
    {
        return new self($position, $sweep, null);
    }

    /** Out. No position, nothing seen, and nothing wrong. */
    public static function left(): self
    {
        return new self(null, null, null);
    }

    public static function refused(TravelRefusal $refusal): self
    {
        return new self(null, null, $refusal);
    }

    public function succeeded(): bool
    {
        return $this->refusal === null;
    }
}
