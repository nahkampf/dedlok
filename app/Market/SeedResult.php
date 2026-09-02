<?php

declare(strict_types=1);

namespace App\Market;

/** What one call to MarketSeeder::run() did. */
final class SeedResult
{
    /** @var list<SeedOutcome> */
    public array $outcomes = [];

    public function record(SeedOutcome $outcome): void
    {
        $this->outcomes[] = $outcome;
    }

    public function itemsConsidered(): int
    {
        return count($this->outcomes);
    }

    /** Items that actually gained stock. */
    public function itemsStocked(): int
    {
        return count(array_filter($this->outcomes, fn (SeedOutcome $o) => $o->added > 0));
    }

    /** Items whose rarity roll failed: the shelf stays as the players left it. */
    public function itemsPassedOver(): int
    {
        return count(array_filter($this->outcomes, fn (SeedOutcome $o) => ! $o->stocked));
    }

    public function unitsAdded(): int
    {
        return array_sum(array_map(fn (SeedOutcome $o) => $o->added, $this->outcomes));
    }

    public function didSomething(): bool
    {
        return $this->unitsAdded() > 0;
    }

    /** The outcome for one slug, for tests and for `--item`. */
    public function for(string $slug): ?SeedOutcome
    {
        foreach ($this->outcomes as $outcome) {
            if ($outcome->item->slug === $slug) {
                return $outcome;
            }
        }

        return null;
    }
}
