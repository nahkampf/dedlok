<?php

declare(strict_types=1);

namespace App\Crafting;

use App\Models\Design;
use App\Models\InventoryItem;

/** What a press on Craft would do for this character right now: the lines, the time, and the first thing standing in the way. */
final readonly class CraftPlan
{
    public function __construct(
        public InventoryItem $row,
        public ?Design $design,
        /** @var list<CraftLine> */
        public array $lines,
        public int $seconds,
        public ?CraftingRefusal $blocked,
    ) {}

    public function wasteful(): bool
    {
        foreach ($this->lines as $line) {
            if ($line->wasteful) {
                return true;
            }
        }

        return false;
    }
}
