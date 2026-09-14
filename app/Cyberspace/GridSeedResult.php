<?php

declare(strict_types=1);

namespace App\Cyberspace;

/**
 * What one pass of the world generator did, or would have done.
 *
 * A dry run fills this in exactly as a real one does and writes nothing, which
 * is what makes "what would happen if I ran this against the live grid" a
 * question with an answer rather than a leap.
 */
final readonly class GridSeedResult
{
    public function __construct(
        public int $positions,
        public int $target,
        public int $occupiedBefore,
        public int $created,
        public bool $portalPlaced,
        public bool $occupantMoved,
        public bool $gridFull,
    ) {}

    public function occupiedAfter(): int
    {
        return $this->occupiedBefore + $this->created;
    }

    /** Percent of the grid holding a system, after the pass. */
    public function occupancy(): float
    {
        return $this->positions === 0 ? 0.0 : round($this->occupiedAfter() / $this->positions * 100, 1);
    }
}
