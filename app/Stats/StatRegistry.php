<?php

declare(strict_types=1);

namespace App\Stats;

/**
 * Every declared stat, loaded from config/stats.php.
 *
 * Held as a plain object rather than resolved out of the container on each
 * lookup, so the Resolver stays constructible in a unit test with a handful of
 * synthetic stats and no framework at all.
 */
final class StatRegistry
{
    /** @param  array<string, Stat>  $stats */
    public function __construct(private readonly array $stats = []) {}

    /** @param  array<string, array<string, mixed>>  $definitions */
    public static function fromDefinitions(array $definitions): self
    {
        $stats = [];

        foreach ($definitions as $id => $definition) {
            $stats[$id] = Stat::fromArray((string) $id, $definition);
        }

        return new self($stats);
    }

    public static function fromConfig(): self
    {
        return self::fromDefinitions((array) config('stats.stats', []));
    }

    public function has(string $id): bool
    {
        return isset($this->stats[$id]);
    }

    public function get(string $id): Stat
    {
        return $this->stats[$id] ?? throw new \InvalidArgumentException(
            "Unknown stat [{$id}]. Declare it in config/stats.php."
        );
    }

    /** @return array<string, Stat> */
    public function all(): array
    {
        return $this->stats;
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->stats);
    }

    /**
     * Every stat a target selects.
     *
     * @return list<string>
     */
    public function matching(Target $target): array
    {
        $matched = [];

        foreach ($this->stats as $id => $stat) {
            if ($target->matches($stat)) {
                $matched[] = $id;
            }
        }

        return $matched;
    }

    /** @return list<string> */
    public function tagged(string ...$tags): array
    {
        return $this->matching(Target::allOf($tags));
    }
}
