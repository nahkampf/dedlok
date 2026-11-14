<?php

declare(strict_types=1);

namespace App\Cyberspace\Tree;

use App\Cyberspace\SystemType;
use App\Models\System;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * A system's run tree: nodes and the edges between them, from the entry to the core (docs/RUN_TREE.md).
 *
 * GENERATED, NEVER STORED. The seed is the system's ADDRESS, which is random, unique and replaced when the system
 * relocates, so the tree stays put through raids and lockdowns and is new after a move, with no column to keep in step.
 * The same address, grade and type always give the same tree.
 *
 * Edges are stored pointing away from the entry (the way they are drawn) but walked both ways.
 */
final readonly class RunTree
{
    /** Crossovers between neighbouring paths, by grade: the whole part always, the fraction as a chance of one more. */
    private const CROSSOVERS = [1 => 0, 2 => 0, 3 => .5, 4 => .5, 5 => 1.4, 6 => 1.4, 7 => 2, 8 => 2, 9 => 2.5];

    /**
     * @param  list<array{kind: NodeKind, path: ?string}>  $nodes  Indexed by node id.
     * @param  list<array{0: int, 1: int}>  $edges
     */
    public function __construct(
        public array $nodes,
        public array $edges,
        public int $entry,
        public int $core,
    ) {}

    /** Whether runs on this system walk a tree (the flag is on, and it is an entity's silo, compute or relay on the main grid). */
    public static function appliesTo(System $system): bool
    {
        return (bool) config('features.intrusion_runs_v2')
            && (int) $system->getAttribute('grid_id') === 1
            && $system->owner_character_id === null
            && in_array($system->type, [SystemType::Silo, SystemType::Compute, SystemType::Relay], true);
    }

    public static function of(System $system): self
    {
        return self::generate($system->address, (int) $system->grade, $system->type);
    }

    public static function generate(string $address, int $grade, SystemType $type): self
    {
        $r = new Randomizer(new Mt19937(crc32($address)));
        $chance = static fn (float $p): bool => $r->nextFloat() < $p;
        $g = max(1, min(9, $grade));

        // Depth: 2 at grade 1 up to 9 at grade 9. The quiet and rich paths are two shorter than that in nodes between
        // the entry and the core; the fast path is two shorter again.
        $depth = 2 + (int) round(($g - 1) * 7 / 8);
        $deep = max(1, $depth - 2);

        $paths = match (true) {
            $g <= 2 => ['main'],
            $g <= 4 => ['fast', match ($type) {
                SystemType::Silo => 'rich',
                SystemType::Relay => 'quiet',
                default => $chance(.5) ? 'quiet' : 'rich',
            }],
            $g >= 7 || $chance(.5) => ['fast', 'quiet', 'rich'],
            default => ['fast', $chance(.5) ? 'quiet' : 'rich'],
        };
        $join = in_array('quiet', $paths, true) && in_array('rich', $paths, true);

        $nodes = [];
        $edges = [];
        $add = static function (NodeKind $kind, ?string $path = null) use (&$nodes): int {
            $nodes[] = ['kind' => $kind, 'path' => $path];

            return count($nodes) - 1;
        };

        $entry = $add(NodeKind::Entry);
        $start = $entry;

        if (count($paths) > 1) {
            $start = $add(NodeKind::Switch);
            $edges[] = [$entry, $start];
        }

        $core = $add(NodeKind::Core);

        // Quiet and rich meet at one conduit before the core (a security hub in phase 2), which counts as their last node.
        $meet = $join ? $add(NodeKind::Conduit) : null;

        if ($meet !== null) {
            $edges[] = [$meet, $core];
        }

        // Each path's nodes as kinds first, so the rules can place before the filler fills.
        $lanes = [];

        foreach ($paths as $path) {
            $length = match ($path) {
                'main' => $depth - 2,
                'fast' => max(1, $deep - 2),
                default => $deep - ($meet !== null ? 1 : 0),
            };
            $seq = array_fill(0, max(0, $length), null);

            if ($path === 'main' && $seq !== []) {
                $seq[0] = $type === SystemType::Silo || $chance(.5) ? NodeKind::Vault : NodeKind::Conduit;
            }

            if ($path === 'fast') {
                $seq[0] = NodeKind::Firewall;

                if ($g >= 7 && $length >= 3) {
                    $seq[$length - 1] = NodeKind::Firewall;
                }
            }

            if ($path === 'rich' && $seq !== []) {
                $seq[0] = NodeKind::Vault;

                if ($length >= 4) {
                    $seq[$length - 1] = NodeKind::Vault;
                }
            }

            // One firewall on every other path from grade 3, wherever there is room.
            $spots = array_keys(array_filter($seq, static fn (?NodeKind $k): bool => $k === null));

            if (in_array($path, ['quiet', 'rich'], true) && $spots !== []) {
                $seq[$spots[$r->getInt(0, count($spots) - 1)]] = NodeKind::Firewall;
            }

            $lanes[$path] = $seq;
        }

        // A silo keeps one more vault, on a free spot of any path (a grade 1-2 silo already has its one).
        if ($type === SystemType::Silo && $g >= 3) {
            $spots = [];

            foreach ($lanes as $path => $seq) {
                foreach ($seq as $i => $kind) {
                    if ($kind === null) {
                        $spots[] = [$path, $i];
                    }
                }
            }

            if ($spots !== []) {
                [$path, $i] = $spots[$r->getInt(0, count($spots) - 1)];
                $lanes[$path][$i] = NodeKind::Vault;
            }
        }

        $own = [];

        foreach ($lanes as $path => $seq) {
            $ids = array_map(static fn (?NodeKind $kind): int => $add($kind ?? NodeKind::Conduit, $path), $seq);
            $end = $meet !== null && in_array($path, ['quiet', 'rich'], true) ? $meet : $core;
            $chain = [$start, ...$ids, $end];

            for ($i = 0; $i < count($chain) - 1; $i++) {
                $edges[] = [$chain[$i], $chain[$i + 1]];
            }

            $own[] = ['ids' => $ids, 'filler' => array_map(static fn (?NodeKind $kind): bool => $kind === null, $seq)];
        }

        // Crossovers: a filler conduit becomes a switch with a second edge into the neighbouring path's next node, so
        // paths meet mid-tree. At most one per gap between columns, so two never draw over each other.
        $target = self::CROSSOVERS[$g];
        $crosses = (int) floor($target) + ($chance(fmod($target, 1.0)) ? 1 : 0);
        $gaps = [];

        for ($guard = 0; $crosses > 0 && $guard < 40 && count($own) > 1; $guard++) {
            $a = $r->getInt(0, count($own) - 1);
            $b = $a + ($chance(.5) ? 1 : -1);

            if (! isset($own[$b])) {
                continue;
            }

            $column = $r->getInt(0, max(0, count($own[$a]['ids']) - 1));
            $src = $own[$a]['ids'][$column] ?? null;
            $dst = $own[$b]['ids'][$column + 1] ?? null;

            if ($src === null || $dst === null || isset($gaps[$column]) || ! $own[$a]['filler'][$column]
                || $nodes[$src]['kind'] !== NodeKind::Conduit || $nodes[$dst]['kind'] === NodeKind::Switch) {
                continue;
            }

            $nodes[$src]['kind'] = NodeKind::Switch;
            $edges[] = [$src, $dst];
            $gaps[$column] = true;
            $crosses--;
        }

        // A maintenance port from grade 3, a dead end off the rich path: off a conduit (which becomes a switch) or a
        // crossover switch where there is one, otherwise off a vault. Never off a firewall. Drawn last, so adding it left
        // every tree before it as it was.
        $rich = array_search('rich', $paths, true);

        if ($g >= 3 && $rich !== false) {
            $ids = $own[$rich]['ids'];
            $splits = array_values(array_filter($ids, static fn (int $id): bool => in_array($nodes[$id]['kind'], [NodeKind::Conduit, NodeKind::Switch], true)));
            $from = $splits !== [] ? $splits : array_values(array_filter($ids, static fn (int $id): bool => $nodes[$id]['kind'] === NodeKind::Vault));

            if ($from !== []) {
                $at = $from[$r->getInt(0, count($from) - 1)];

                if ($nodes[$at]['kind'] === NodeKind::Conduit) {
                    $nodes[$at]['kind'] = NodeKind::Switch;
                }

                $edges[] = [$at, $add(NodeKind::Port)];
            }
        }

        return new self($nodes, $edges, $entry, $core);
    }

    /** Where egress is possible: the entry or a maintenance port. */
    public function isExit(int $node): bool
    {
        return $node === $this->entry || ($this->nodes[$node]['kind'] ?? null) === NodeKind::Port;
    }

    /** @return list<int> The nodes one edge away, either direction. */
    public function neighbours(int $node): array
    {
        $out = [];

        foreach ($this->edges as [$a, $b]) {
            if ($a === $node) {
                $out[] = $b;
            } elseif ($b === $node) {
                $out[] = $a;
            }
        }

        return $out;
    }

    /** Fewest moves between two nodes, or null when nothing connects them. */
    public function distance(int $from, int $to): ?int
    {
        $seen = [$from => 0];
        $queue = [$from];

        while ($queue !== []) {
            $node = array_shift($queue);

            if ($node === $to) {
                return $seen[$node];
            }

            foreach ($this->neighbours($node) as $next) {
                if (! isset($seen[$next])) {
                    $seen[$next] = $seen[$node] + 1;
                    $queue[] = $next;
                }
            }
        }

        return null;
    }

    /** @return list<int> Every node within `$steps` edges of `$node`, not counting it. */
    public function within(int $node, int $steps): array
    {
        $seen = [$node => 0];
        $queue = [$node];

        while ($queue !== []) {
            $at = array_shift($queue);

            if ($seen[$at] >= $steps) {
                continue;
            }

            foreach ($this->neighbours($at) as $next) {
                if (! isset($seen[$next])) {
                    $seen[$next] = $seen[$at] + 1;
                    $queue[] = $next;
                }
            }
        }

        unset($seen[$node]);

        return array_keys($seen);
    }

    /**
     * Where each node is drawn, left to right: its column is the longest way to it from the entry along the edges (so a
     * crossover never points backwards), its lane the path it belongs to. Nodes on no path (the entry, a switch where
     * paths split, the conduit where quiet and rich meet, the core) sit between the lanes they join; a maintenance port
     * in a lane of its own below the rich path it hangs off.
     *
     * @return array<int, array{col: int, lane: float}>
     */
    public function layout(): array
    {
        $order = ['main' => 0, 'fast' => 0, 'quiet' => 1, 'rich' => 2];
        $paths = array_values(array_unique(array_filter(array_column($this->nodes, 'path'))));
        usort($paths, static fn (string $a, string $b): int => $order[$a] <=> $order[$b]);
        $lanes = array_flip($paths);
        $middle = max(0, count($paths) - 1) / 2;

        $col = [$this->entry => 0];
        $changed = true;

        // Longest path in a graph with no cycles: relax until nothing moves (it settles in at most a node's count).
        while ($changed) {
            $changed = false;

            foreach ($this->edges as [$a, $b]) {
                if (isset($col[$a]) && ($col[$b] ?? -1) < $col[$a] + 1) {
                    $col[$b] = $col[$a] + 1;
                    $changed = true;
                }
            }
        }

        $out = [];

        foreach ($this->nodes as $id => $node) {
            $lane = $node['path'] !== null ? (float) $lanes[$node['path']] : $middle;

            if ($node['kind'] === NodeKind::Port) {
                $lane = (float) count($paths);
            }

            // The conduit where quiet and rich meet sits between them.
            if ($node['path'] === null && $node['kind'] === NodeKind::Conduit && isset($lanes['quiet'], $lanes['rich'])) {
                $lane = ($lanes['quiet'] + $lanes['rich']) / 2;
            }

            $out[$id] = ['col' => $col[$id] ?? 0, 'lane' => $lane];
        }

        return $out;
    }

    /** How many lanes the drawing needs. */
    public function lanes(): int
    {
        return max(1, count(array_unique(array_filter(array_column($this->nodes, 'path'))))) + ($this->nodesOf(NodeKind::Port) !== [] ? 1 : 0);
    }

    /** @return list<int> */
    public function nodesOf(NodeKind $kind): array
    {
        return array_keys(array_filter($this->nodes, static fn (array $n): bool => $n['kind'] === $kind));
    }
}
