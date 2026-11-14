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
     * @param  list<array{kind: NodeKind, path: ?string, trap?: TrapEffect}>  $nodes  Indexed by node id; a trap carries its effect.
     * @param  list<array{0: int, 1: int}>  $edges
     * @param  list<array{post: int, fast: bool, cordon?: bool}>  $sentries  Where each sentry is posted, whether it is Fast, and whether it carries the Cordon tag.
     */
    public function __construct(
        public array $nodes,
        public array $edges,
        public int $entry,
        public int $core,
        public array $sentries = [],
    ) {}

    /** Whether runs on this system walk a tree (the flag is on, and it is an entity's silo, compute or relay on the main grid). */
    public static function appliesTo(System $system): bool
    {
        return (bool) config('features.intrusion_runs_v2')
            && (int) $system->getAttribute('grid_id') === 1
            && $system->owner_character_id === null
            && in_array($system->type, [SystemType::Silo, SystemType::Compute, SystemType::Relay], true);
    }

    /** A system with no IDS has no security hub: the node is a conduit there. */
    public static function of(System $system): self
    {
        $tree = self::generate($system->address, (int) $system->grade, $system->type);

        if ((float) $system->ids_detection > 0) {
            return $tree;
        }

        $nodes = array_map(static fn (array $n): array => $n['kind'] === NodeKind::Hub ? ['kind' => NodeKind::Conduit, 'path' => $n['path']] : $n, $tree->nodes);

        return new self($nodes, $tree->edges, $tree->entry, $tree->core, $tree->sentries);
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

        // Quiet and rich meet at a security hub before the core, which counts as their last node.
        $meet = $join ? $add(NodeKind::Hub) : null;

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

        // Internal relays from grade 3 on the quiet path: one of its conduits, and a second on a relay system where there
        // is room. Drawn after the port, so the trees before them kept every other node.
        $quiet = array_search('quiet', $paths, true);

        if ($g >= 3 && $quiet !== false) {
            for ($n = $type === SystemType::Relay ? 2 : 1; $n > 0; $n--) {
                $free = array_values(array_filter($own[$quiet]['ids'], static fn (int $id): bool => $nodes[$id]['kind'] === NodeKind::Conduit));

                if ($free === []) {
                    break;
                }

                $nodes[$free[$r->getInt(0, count($free) - 1)]]['kind'] = NodeKind::Relay;
            }
        }

        // Traps from grade 4: the quiet and the rich path may each carry one (about a third of the time at grades
        // 4-6, a little over half from 7), and a silo keeps one more where there is room. Never on the fast path. Drawn
        // last, so the trees before them kept every other node.
        if ($g >= 4) {
            // A free conduit becomes the trap; with none, a new node is put between two of the path's own, one move longer.
            $trap = static function (array $ids) use (&$nodes, &$edges, $r): void {
                $free = array_values(array_filter($ids, static fn (int $id): bool => $nodes[$id]['kind'] === NodeKind::Conduit));
                $effect = TrapEffect::cases()[$r->getInt(0, count(TrapEffect::cases()) - 1)];

                if ($free !== []) {
                    $at = $free[$r->getInt(0, count($free) - 1)];
                    $nodes[$at] = ['kind' => NodeKind::Trap, 'path' => $nodes[$at]['path'], 'trap' => $effect];

                    return;
                }

                $links = array_keys(array_filter($edges, static fn (array $e): bool => in_array($e[0], $ids, true) && in_array($e[1], $ids, true)
                    && $nodes[$e[0]]['path'] === $nodes[$e[1]]['path']));

                if ($links === []) {
                    return;
                }

                $i = $links[$r->getInt(0, count($links) - 1)];
                [$from, $to] = $edges[$i];
                $nodes[] = ['kind' => NodeKind::Trap, 'path' => $nodes[$from]['path'], 'trap' => $effect];
                $edges[$i] = [$from, count($nodes) - 1];
                $edges[] = [count($nodes) - 1, $to];
            };
            $lanes = array_keys(array_intersect($paths, ['quiet', 'rich']));

            foreach ($lanes as $lane) {
                if ($chance($g >= 7 ? .55 : .3)) {
                    $trap($own[$lane]['ids']);
                }
            }

            if ($type === SystemType::Silo && $lanes !== []) {
                $trap(array_merge(...array_map(static fn (int $lane): array => $own[$lane]['ids'], $lanes)));
            }
        }

        // A log server from grade 5: a dead end off a conduit (which becomes a switch) or a switch of the quiet or rich path.
        if ($g >= 5) {
            $from = [];

            foreach (array_keys(array_intersect($paths, ['quiet', 'rich'])) as $lane) {
                foreach ($own[$lane]['ids'] as $id) {
                    if (in_array($nodes[$id]['kind'], [NodeKind::Conduit, NodeKind::Switch], true)) {
                        $from[] = $id;
                    }
                }
            }

            if ($from !== []) {
                $at = $from[$r->getInt(0, count($from) - 1)];

                if ($nodes[$at]['kind'] === NodeKind::Conduit) {
                    $nodes[$at]['kind'] = NodeKind::Switch;
                }

                $edges[] = [$at, $add(NodeKind::Log)];
            }
        }

        // Honeypots from grade 5: a vault other than the tree's first is sometimes a trap with bait (a quarter of the time,
        // two in five on a silo). It stays a vault in every reading (only Deep Nmap tells), holds nothing, and goes off on
        // the first Extract. Never a Node Disconnect: the runner is standing on it.
        if ($g >= 5) {
            foreach (array_slice(array_keys(array_filter($nodes, static fn (array $n): bool => $n['kind'] === NodeKind::Vault)), 1) as $vault) {
                if ($chance($type === SystemType::Silo ? .4 : .25)) {
                    $nodes[$vault]['trap'] = [TrapEffect::Alarm, TrapEffect::Trace, TrapEffect::Ensnare][$r->getInt(0, 2)];
                }
            }
        }

        // Sentries: none at grades 1-3, one at 4-5, two at 6-7, three at 8-9. Posted on vaults first (never a honeypot),
        // then on other nodes of the paths (never a trap, the entry, the core, a port or a log server). Fast one time in three
        // from grade 7.
        $sentries = [];
        $count = match (true) {
            $g <= 3 => 0,
            $g <= 5 => 1,
            $g <= 7 => 2,
            default => 3,
        };
        $vaults = array_values(array_filter(array_keys($nodes), static fn (int $n): bool => $nodes[$n]['kind'] === NodeKind::Vault && ! isset($nodes[$n]['trap'])));
        $others = array_values(array_filter(array_keys($nodes), static fn (int $n): bool => $nodes[$n]['path'] !== null && ! in_array($nodes[$n]['kind'], [NodeKind::Vault, NodeKind::Trap], true)));
        $order = [...$r->shuffleArray($vaults), ...$r->shuffleArray($others)];

        foreach (array_slice($order, 0, $count) as $post) {
            $sentries[] = ['post' => $post, 'fast' => $g >= 7 && $chance(1 / 3)];
        }

        // An ICE controller from grade 6: a dead end off the core.
        if ($g >= 6) {
            $edges[] = [$core, $add(NodeKind::Controller)];
        }

        // The Cordon tag from grade 5, one sentry in three: it cuts the way out instead of hunting.
        foreach ($sentries as $i => $sentry) {
            $sentries[$i]['cordon'] = $g >= 5 && $chance(1 / 3);
        }

        return new self($nodes, $edges, $entry, $core, $sentries);
    }

    /** A honeypot: a vault that is a trap with bait. */
    public function isHoneypot(int $node): bool
    {
        return ($this->nodes[$node]['kind'] ?? null) === NodeKind::Vault && isset($this->nodes[$node]['trap']);
    }

    /** The same tree with these nodes torn out (Node Disconnect): they keep their ids and lose every edge. @param  list<int>  $cut */
    public function cut(array $cut): self
    {
        if ($cut === []) {
            return $this;
        }

        return new self($this->nodes, array_values(array_filter($this->edges, static fn (array $e): bool => ! in_array($e[0], $cut, true) && ! in_array($e[1], $cut, true))), $this->entry, $this->core, $this->sentries);
    }

    /** Where a backdoor brings its holder in: the maintenance port, or the core on a tree without one. */
    public function backdoorStart(): int
    {
        return $this->nodesOf(NodeKind::Port)[0] ?? $this->core;
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

    /** @return list<int> One shortest way from a node to another, both ends included; empty when nothing connects them. */
    public function path(int $from, int $to): array
    {
        $parent = [$from => null];
        $queue = [$from];

        while ($queue !== [] && ! array_key_exists($to, $parent)) {
            $node = array_shift($queue);

            foreach ($this->neighbours($node) as $next) {
                if (! array_key_exists($next, $parent)) {
                    $parent[$next] = $node;
                    $queue[] = $next;
                }
            }
        }

        if (! array_key_exists($to, $parent)) {
            return [];
        }

        for ($path = [], $at = $to; $at !== null; $at = $parent[$at]) {
            array_unshift($path, $at);
        }

        return $path;
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
     * paths split, the hub where quiet and rich meet, the core) sit between the lanes they join; a maintenance port
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

            // Dead ends drawn in lanes of their own below the paths: the port, then the log server.
            if ($node['kind'] === NodeKind::Port) {
                $lane = (float) count($paths);
            }

            if ($node['kind'] === NodeKind::Log) {
                $lane = (float) count($paths) + ($this->nodesOf(NodeKind::Port) !== [] ? 1 : 0);
            }

            // The hub where quiet and rich meet (a conduit with no IDS) sits between them.
            if ($node['path'] === null && in_array($node['kind'], [NodeKind::Hub, NodeKind::Conduit], true) && isset($lanes['quiet'], $lanes['rich'])) {
                $lane = ($lanes['quiet'] + $lanes['rich']) / 2;
            }

            $out[$id] = ['col' => $col[$id] ?? 0, 'lane' => $lane];
        }

        return $out;
    }

    /** How many lanes the drawing needs. */
    public function lanes(): int
    {
        return max(1, count(array_unique(array_filter(array_column($this->nodes, 'path'))))) + ($this->nodesOf(NodeKind::Port) !== [] ? 1 : 0) + ($this->nodesOf(NodeKind::Log) !== [] ? 1 : 0);
    }

    /** @return list<int> */
    public function nodesOf(NodeKind $kind): array
    {
        return array_keys(array_filter($this->nodes, static fn (array $n): bool => $n['kind'] === $kind));
    }
}
