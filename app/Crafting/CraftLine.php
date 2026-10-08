<?php

declare(strict_types=1);

namespace App\Crafting;

use App\Models\DesignComponent;
use App\Models\InventoryItem;
use App\Models\Item;

/**
 * One line of a recipe as THIS character would meet it: how many are wanted (after crafting_cost), how many qualifying ones they hold,
 * which rows would be spent, and whether that would use a higher grade than the line asks for.
 */
final readonly class CraftLine
{
    public function __construct(
        public DesignComponent $line,
        public Item $component,
        public int $need,
        public int $have,
        /** @var list<array{row: InventoryItem, take: int}> the rows that would be spent, lowest grade first */
        public array $picks,
        /** A chosen file is of a higher grade than the line's minimum. */
        public bool $wasteful,
        /** What the line asks for in words: "Kernel Log, AuraOS". */
        public string $label,
    ) {}

    public function met(): bool
    {
        return $this->have >= $this->need;
    }
}
