<?php

declare(strict_types=1);

namespace App\Skills;

/**
 * Skill point thresholds, and the level a given number of points has reached.
 *
 * Pure and config-driven: no database, no models. The curve is expected to be
 * tuned, so it is isolated here rather than scattered through the training
 * code.
 */
final class SkillProgression
{
    /**
     * @param  list<int>  $ranks
     */
    public function __construct(
        private readonly int $maxLevel = 5,
        private readonly int $levelOneBase = 242,
        private readonly float $scalingFactor = 0.8,
        private readonly float $growthMultiplier = 5.95,
        private readonly array $ranks = [1, 2, 4, 8, 16],
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            maxLevel: (int) config('skills.max_level', 5),
            levelOneBase: (int) config('skills.thresholds.level_one_base', 242),
            scalingFactor: (float) config('skills.thresholds.scaling_factor', 0.8),
            growthMultiplier: (float) config('skills.thresholds.growth_multiplier', 5.95),
            ranks: (array) config('skills.ranks', [1, 2, 4, 8, 16]),
        );
    }

    public function maxLevel(): int
    {
        return $this->maxLevel;
    }

    /** @return list<int> */
    public function ranks(): array
    {
        return $this->ranks;
    }

    public function isValidRank(int $rank): bool
    {
        return in_array($rank, $this->ranks, true);
    }

    /** The rank-independent multiplier for a level: 1, 5, 28, 169, 1003. */
    public function multiplier(int $level): int
    {
        $this->guardLevel($level);

        return (int) round($this->scalingFactor * ($this->growthMultiplier ** ($level - 1)));
    }

    /**
     * Cumulative points needed to HAVE REACHED a level — not the points to get
     * there from the level below.
     */
    public function threshold(int $level, int $rank): int
    {
        $this->guardRank($rank);

        return $this->levelOneBase * $rank * $this->multiplier($level);
    }

    /**
     * Every threshold for a rank, keyed by level.
     *
     * @return array<int, int>
     */
    public function thresholds(int $rank): array
    {
        $thresholds = [];

        for ($level = 1; $level <= $this->maxLevel; $level++) {
            $thresholds[$level] = $this->threshold($level, $rank);
        }

        return $thresholds;
    }

    /**
     * The most points a skill can hold.
     *
     * Accrual stops here: overspill past level 5 is discarded rather than
     * banked, so the column has a real upper bound.
     */
    public function cap(int $rank): int
    {
        return $this->threshold($this->maxLevel, $rank);
    }

    /** The level a point total has reached. 0 means trained but not yet level 1. */
    public function levelFor(int $points, int $rank): int
    {
        $level = 0;

        for ($candidate = 1; $candidate <= $this->maxLevel; $candidate++) {
            if ($points >= $this->threshold($candidate, $rank)) {
                $level = $candidate;

                continue;
            }

            break;
        }

        return $level;
    }

    /** Points still needed for the next level, or null at the cap. */
    public function pointsToNextLevel(int $points, int $rank): ?int
    {
        $level = $this->levelFor($points, $rank);

        if ($level >= $this->maxLevel) {
            return null;
        }

        return max(0, $this->threshold($level + 1, $rank) - $points);
    }

    private function guardLevel(int $level): void
    {
        if ($level < 1 || $level > $this->maxLevel) {
            throw new \InvalidArgumentException(
                "Level [{$level}] is outside 1..{$this->maxLevel}."
            );
        }
    }

    private function guardRank(int $rank): void
    {
        if (! $this->isValidRank($rank)) {
            throw new \InvalidArgumentException(
                "Rank [{$rank}] is not one of: ".implode(', ', $this->ranks).'.'
            );
        }
    }
}
