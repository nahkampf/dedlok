<?php

declare(strict_types=1);

namespace App\City;

/**
 * The quickest route between two districts. Pure: ids and weights, never models.
 *
 * A route costs the weights of every district on it EXCEPT the destination —
 * the origin included — because a pass is spent in a district and the
 * destination never gets one. Stepping from u to v therefore costs u's weight.
 * (Every route between two fixed districts shares both ends, so the choice
 * between routes comes down to the districts in between; the rule matters for
 * what a route COSTS, which is what the time and the quote read.)
 *
 * Ties go to fewer districts, then to the lower ids, so a route is
 * deterministic.
 */
final class Router
{
    /**
     * @param  list<array{0: int, 1: int}>  $links  undirected pairs of district ids
     * @param  array<int, float>  $weights  district id => travel weight
     * @return list<int>|null origin first, destination last; null when unreachable
     */
    public static function route(array $links, array $weights, int $from, int $to): ?array
    {
        if ($from === $to) {
            return [$from];
        }

        $next = [];
        foreach ($links as [$a, $b]) {
            $next[$a][] = $b;
            $next[$b][] = $a;
        }

        // [cost, hops, path] per district; the frontier is small, so a plain
        // scan for the minimum beats a heap's ceremony.
        $best = [$from => [0.0, 0, [$from]]];
        $done = [];

        while (true) {
            $current = null;
            foreach ($best as $id => $entry) {
                if (isset($done[$id])) {
                    continue;
                }
                if ($current === null || self::before($entry, $id, $best[$current], $current)) {
                    $current = $id;
                }
            }

            if ($current === null) {
                return null;
            }

            if ($current === $to) {
                return $best[$to][2];
            }

            $done[$current] = true;
            [$cost, $hops, $path] = $best[$current];
            $step = $cost + ($weights[$current] ?? 1.0);

            foreach ($next[$current] ?? [] as $neighbour) {
                $candidate = [$step, $hops + 1, [...$path, $neighbour]];

                if (! isset($best[$neighbour]) || self::better($candidate, $best[$neighbour])) {
                    $best[$neighbour] = $candidate;
                }
            }
        }
    }

    /** @param  array{0: float, 1: int, 2: list<int>}  $a  @param  array{0: float, 1: int, 2: list<int>}  $b */
    private static function better(array $a, array $b): bool
    {
        // Costs are sums of the same few numbers, so compare with a tolerance
        // rather than trust float equality for a tie.
        if (abs($a[0] - $b[0]) > 1e-9) {
            return $a[0] < $b[0];
        }

        if ($a[1] !== $b[1]) {
            return $a[1] < $b[1];
        }

        return $a[2] < $b[2];
    }

    /** @param  array{0: float, 1: int, 2: list<int>}  $a  @param  array{0: float, 1: int, 2: list<int>}  $b */
    private static function before(array $a, int $aId, array $b, int $bId): bool
    {
        return self::better($a, $b) || ($a === $b && $aId < $bId);
    }
}
