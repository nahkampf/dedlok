<?php

declare(strict_types=1);

namespace App\Drugs;

use App\Models\Dose;

/**
 * What taking one did.
 *
 * Carries the overdose because the screen has to say so out loud: what it
 * costs is a ceiling, a body and sometimes a heart, and none of those is
 * something a player should have to infer from a number moving somewhere else.
 */
final readonly class DoseResult
{
    private function __construct(
        public ?Dose $dose,
        public ?DoseRefusal $refusal,
        public float $potency = 1.0,
        public Overdose $overdose = new Overdose,
    ) {}

    public static function taken(Dose $dose, float $potency, Overdose $overdose): self
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

    /** Did it cost them more than it gave? */
    public function overdosed(): bool
    {
        return $this->overdose->happened();
    }
}
