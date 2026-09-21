<?php

declare(strict_types=1);

namespace App\Drugs;

/**
 * How far past the body's limit one dose went, and the three separate things
 * that costs.
 *
 * THEY ARE DIFFERENT KINDS OF THING ON PURPOSE. The ceiling coming down is an
 * INJURY — permanent until a clinic exists, and not mitigable, because
 * mitigation has nothing to say about a ceiling. The body taking it is
 * ordinary DAMAGE and goes through the door, so a constitution can be written
 * against `all_of: ['damage', 'overdose']`. And the heart stopping is neither:
 * it is an event, it is not a matter of degree, and nothing resists it.
 */
final readonly class Overdose
{
    public function __construct(
        public int $excess = 0,
        public float $injury = 0.0,
        public int $hitpoints = 0,
        public bool $lethal = false,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public function happened(): bool
    {
        return $this->excess > 0;
    }
}
