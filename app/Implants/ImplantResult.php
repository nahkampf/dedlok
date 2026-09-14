<?php

declare(strict_types=1);

namespace App\Implants;

use App\Models\InventoryItem;

/**
 * What came of installing or removing an implant.
 *
 * The same shape as FittingResult and TravelResult: a refusal is a value, not
 * an exception.
 */
final class ImplantResult
{
    private function __construct(
        public readonly ?InventoryItem $implant,
        public readonly ?ImplantRefusal $refusal,
    ) {}

    public static function installed(InventoryItem $implant): self
    {
        return new self($implant, null);
    }

    public static function removed(InventoryItem $implant): self
    {
        return new self($implant, null);
    }

    public static function refused(ImplantRefusal $refusal): self
    {
        return new self(null, $refusal);
    }

    public function succeeded(): bool
    {
        return $this->refusal === null;
    }
}
