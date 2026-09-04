<?php

declare(strict_types=1);

namespace App\Stats;

/**
 * Which stats are computed from which, and in what order they can be resolved.
 *
 * A derived stat's base is a formula over OTHER stats, and MECHANICS.md is
 * emphatic that those inputs are the RESOLVED values rather than the stored
 * ones: an implant that raises Focus has to raise maximum HP, because maximum
 * HP is half of Focus and Focus is now higher. Reading the column instead is
 * the bug this class exists to make impossible — it was the behaviour before
 * the graph existed, and nothing announced it.
 *
 * Pure, like everything else in this namespace: it holds stat ids and nothing
 * else. No Eloquent, no config lookup, no clock.
 *
 * A cycle is a fatal declaration error rather than a runtime surprise, and it
 * is caught the first time anything asks for an order — so a formula that says
 * "A is half of B" alongside "B is half of A" fails at boot with both names in
 * the message, not in a stack overflow three screens later.
 */
final class StatGraph
{
    /** @var array<string, list<string>> */
    private readonly array $dependencies;

    /** @var list<string>|null */
    private ?array $order = null;

    /**
     * @param  array<string, list<string>>  $dependencies  stat id => the stat ids it reads
     */
    public function __construct(array $dependencies = [])
    {
        $normalised = [];

        foreach ($dependencies as $statId => $inputs) {
            $normalised[(string) $statId] = array_values(array_unique(array_map('strval', $inputs)));
        }

        $this->dependencies = $normalised;
    }

    /** What this stat reads. Empty for a stored one. */
    public function inputsFor(string $statId): array
    {
        return $this->dependencies[$statId] ?? [];
    }

    public function isDerived(string $statId): bool
    {
        return ($this->dependencies[$statId] ?? []) !== [];
    }

    /** @return array<string, list<string>> */
    public function all(): array
    {
        return $this->dependencies;
    }

    /**
     * Every stat mentioned anywhere in the graph, in an order where nothing is
     * resolved before what it reads.
     *
     * Stored stats — the ones nothing computes — come out first, because they
     * depend on nothing and everything depends on them.
     *
     * @return list<string>
     */
    public function order(): array
    {
        return $this->order ??= $this->sort();
    }

    /**
     * This stat and everything it transitively reads, in resolution order.
     *
     * What a single stat read needs, rather than the whole sheet: asking for
     * `check.intrusion` should not resolve every other check in the game.
     *
     * A stat that reads nothing comes back as a list of itself — it still has
     * to be resolved, it simply has nothing to wait for.
     *
     * @return list<string>
     */
    public function orderFor(string $statId): array
    {
        $sorted = [];
        $state = [];

        $this->visit($statId, $state, $sorted, []);

        return $sorted;
    }

    /**
     * Depth-first topological sort, with the cycle named if there is one.
     *
     * @return list<string>
     */
    private function sort(): array
    {
        $sorted = [];
        $state = [];   // statId => 'visiting' | 'done'

        foreach (array_keys($this->dependencies) as $statId) {
            $this->visit($statId, $state, $sorted, []);
        }

        return $sorted;
    }

    /**
     * @param  array<string, string>  $state
     * @param  list<string>  $sorted
     * @param  list<string>  $path  how we got here, for the cycle message
     */
    private function visit(string $statId, array &$state, array &$sorted, array $path): void
    {
        if (($state[$statId] ?? null) === 'done') {
            return;
        }

        if (($state[$statId] ?? null) === 'visiting') {
            $cycle = array_slice($path, array_search($statId, $path, true) ?: 0);
            $cycle[] = $statId;

            throw new \InvalidArgumentException(
                'Stat formulas form a cycle: '.implode(' -> ', $cycle).
                '. A derived stat cannot read something that reads it back.'
            );
        }

        $state[$statId] = 'visiting';
        $path[] = $statId;

        foreach ($this->inputsFor($statId) as $input) {
            $this->visit($input, $state, $sorted, $path);
        }

        $state[$statId] = 'done';
        $sorted[] = $statId;
    }
}
