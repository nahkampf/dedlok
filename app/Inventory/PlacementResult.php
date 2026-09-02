<?php

declare(strict_types=1);

namespace App\Inventory;

use App\Models\InventoryItem;

/** What one call to ItemPlacer::place() did. */
final class PlacementResult
{
    private function __construct(
        public readonly ?InventoryItem $row = null,
        public readonly ?PlacementRefusal $refusal = null,
    ) {}

    public static function placed(InventoryItem $row): self
    {
        return new self(row: $row);
    }

    public static function refused(PlacementRefusal $refusal): self
    {
        return new self(refusal: $refusal);
    }

    public function succeeded(): bool
    {
        return $this->refusal === null;
    }
}
