<?php

declare(strict_types=1);

namespace App\Market;

use App\Models\Item;

/**
 * What a purchase came to.
 *
 * Carries the price paid rather than letting the screen recompute it: what the
 * player is told they spent has to be what actually left their account, and a
 * second multiplication is a second chance to disagree.
 */
final readonly class PurchaseResult
{
    private function __construct(
        public ?Item $item,
        public ?PurchaseRefusal $refusal,
        public int $quantity = 0,
        public int $paid = 0,
    ) {}

    public static function bought(Item $item, int $quantity, int $paid): self
    {
        return new self($item, null, $quantity, $paid);
    }

    public static function refused(PurchaseRefusal $refusal): self
    {
        return new self(null, $refusal);
    }

    public function succeeded(): bool
    {
        return $this->refusal === null;
    }
}
