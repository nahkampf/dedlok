<?php

declare(strict_types=1);

namespace App\Drugs;

use App\Models\Dose;

/**
 * What taking one did.
 *
 * Carries the overdose because the screen has to say so out loud: it is the
 * one outcome a player must not have to infer from a number moving somewhere
 * else.
 */
final readonly class DoseResult
{
    private function __construct(
        public ?Dose $dose,
        public ?DoseRefusal $refusal,
        public float $potency = 1.0,
        public float $overdose = 0.0,
    ) {}

    public static function taken(Dose $dose, float $potency, float $overdose): self
    {
        return new self($dose, null, $potency, $overdose);
    }

    public static function refused(DoseRefusal $refusal): self
    {
        return new self(null, $refusal);
    }

    public function succeeded(): bool
    {
        return $this->refusal === null;
    }

    /** Did it cost them nerve tissue? */
    public function overdosed(): bool
    {
        return $this->overdose > 0.0;
    }
}
