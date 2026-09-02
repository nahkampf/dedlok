<?php

declare(strict_types=1);

namespace App\Skills;

/**
 * How many skill points a character earns per minute training a given skill.
 *
 * Deliberately one small class with one formula. The weighting is expected to
 * change, and this is the only place that has to change with it.
 *
 * Callers pass RESOLVED attribute values, not the stored columns — so an
 * implant that raises Focus speeds up Focus-primary skills for free.
 */
final class TrainingRate
{
    public function __construct(
        private readonly float $primaryWeight = 0.121,
        private readonly float $secondaryWeight = 0.0762,
        private readonly int $secondsPerMinute = 60,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            primaryWeight: (float) config('skills.training_rate.primary_weight', 0.121),
            secondaryWeight: (float) config('skills.training_rate.secondary_weight', 0.0762),
            secondsPerMinute: (int) config('skills.training_rate.seconds_per_minute', 60),
        );
    }

    public function perMinute(float $primary, float $secondary): float
    {
        return $this->secondsPerMinute
            * (($this->primaryWeight * $primary) + ($this->secondaryWeight * $secondary));
    }

    /** Minutes to accrue a number of points at this rate, or null if it never will. */
    public function minutesFor(int $points, float $primary, float $secondary): ?float
    {
        $rate = $this->perMinute($primary, $secondary);

        return $rate > 0 ? $points / $rate : null;
    }
}
