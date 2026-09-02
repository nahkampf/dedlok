<?php

declare(strict_types=1);

namespace App\Market;

use App\Models\Item;

/**
 * What one restock pass decided about one item.
 *
 * Kept even when nothing happened, because "nothing happened" is the
 * interesting case: a rare item that failed its roll and an item nobody is
 * buying both add zero, and only this says which was which.
 */
final class SeedOutcome
{
    public function __construct(
        public readonly Item $item,
        /** Units bought inside the demand window. */
        public readonly int $demand,
        /** The shelf count this pass was aiming for. */
        public readonly int $target,
        /** What was on the shelf before it ran. */
        public readonly int $before,
        /** What it actually added. Zero when the roll failed or the shelf was full. */
        public readonly int $added,
        /** Whether the rarity roll passed. */
        public readonly bool $stocked,
        public readonly int $roll,
    ) {}

    public function after(): int
    {
        return $this->before + $this->added;
    }

    /** Passed its roll but had nothing to add: the shelf was already there. */
    public function wasAlreadyFull(): bool
    {
        return $this->stocked && $this->added === 0;
    }
}
