<?php

declare(strict_types=1);

namespace App\Cyberspace\Tree;

use App\Cyberspace\RunAction;
use App\Cyberspace\Simulator;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Run;
use App\Models\System;
use App\Models\SystemScan;
use App\Support\Text;

/**
 * What the run screen draws of a tree run (docs/RUN_TREE.md): the nodes this runner can see, where, and which they can
 * move to; and the noise bar.
 *
 * FOG: a node whose kind is known (stood on or Nmapped, by them or their party) shows it; a node one edge from a known
 * one shows as "?" (every node does, once a scan has read the Layout); the rest is not drawn, and the board is only as wide as the columns that are, so its width gives
 * nothing away.
 */
final readonly class TreeView
{
    /**
     * @param  list<array{key: int, label: string, x: float, y: float, here: bool, allies: int, move: bool, known: bool}>  $nodes
     * @param  list<array{0: int, 1: int}>  $links
     */
    public function __construct(
        public array $nodes,
        public array $links,
        public int $cols,
        public int $lanes,
        // On the entry or a maintenance port: where egress (and a Flood) is possible.
        public bool $atExit,
        public bool $atCore,
        public bool $canNmap,
        public float $noise,
        // The bar's depth when the runner's scan read the IDS (0: it has none); null when it did not.
        public ?float $ids,
        // Standing on a firewall that is still closed: the chance its OS roll lands (the core's own figure). Null otherwise.
        public ?float $firewallChance = null,
        // Standing on a data vault: it takes an Extract on any purpose.
        public bool $atVault = false,
        // Standing on a security hub there is still something to do about: the chance its OS roll lands. Null otherwise.
        public ?float $hubChance = null,
        // The party has subverted the hub: no alert can go off.
        public bool $subverted = false,
        // Standing on an internal relay with no Loopback of theirs holding: the Loopback action is offered there.
        public bool $atRelay = false,
        // The chance a Loopback holds each turn: theirs while it holds, otherwise the copy they would start. Null for none.
        public ?float $loopbackChance = null,
        // Their own Loopback holds on the relay they stand on.
        public bool $looping = false,
        /** @var list<array{0: int, 1: string}> Found traps one edge away, each a Disarm: [node, label]. */
        public array $disarm = [],
        // The chance a Disarm's OS roll lands; null with nothing to disarm.
        public ?float $disarmChance = null,
    ) {}

    /** What a node is called on the map: a trap reads as a conduit until it is found, and as one again once spent. */
    private static function label(RunTree $tree, int $node, bool $found): string
    {
        $kind = $tree->nodes[$node]['kind'];

        if ($kind === NodeKind::Trap && $found) {
            return Text::get('cyberspace.tree.trap_label', ['effect' => $tree->nodes[$node]['trap']->label()]);
        }

        return Text::get('cyberspace.tree.node.'.($kind === NodeKind::Trap ? NodeKind::Conduit : $kind)->value);
    }

    public static function for(Run $run, Character $character, Simulator $simulator, ?SystemScan $scan): ?self
    {
        $system = $run->system();

        if (! $run->isTreeRun() || $system === null) {
            return null;
        }

        // Drawn where the whole tree puts them, walked as the party left it (a Node Disconnect takes a node and its edges).
        $whole = RunTree::of($system);
        $cut = $run->cutNodes();
        $tree = $whole->cut($cut);
        $traps = $run->trapStates();
        $here = (int) $run->node;
        $known = array_values(array_diff($run->knownNodes(), $cut));
        $seen = $known;

        foreach ($known as $node) {
            $seen = [...$seen, ...$tree->neighbours($node)];
        }

        // A scan that read the Layout showed the whole shape before the run began.
        $mapped = $run->mapped();
        $seen = array_values(array_diff($mapped ? array_keys($tree->nodes) : array_unique($seen), $cut));
        $layout = $whole->layout();
        $maxCol = max(array_map(static fn (int $n): int => $layout[$n]['col'], $seen));
        $lanes = $tree->lanes();
        $allies = $run->run_party_id === null ? [] : Run::query()->live()->where('run_party_id', $run->run_party_id)
            ->whereKeyNot($run->getKey())->pluck('node')->countBy()->all();
        $moves = $tree->neighbours($here);

        // A firewall nobody has breached lets them back the way they came, and no further.
        if ($simulator->firewallClosed($run)) {
            $moves = array_values(array_filter($moves, static fn (int $n): bool => ! in_array([$here, $n], $tree->edges, true)));
        }

        $nodes = array_map(static fn (int $n): array => [
            'key' => $n,
            'label' => in_array($n, $known, true) || ($traps[$n] ?? null) === 'found' ? self::label($tree, $n, ($traps[$n] ?? null) === 'found') : '?',
            // Centred in its own column, so the first and last boxes sit half a column in from the edges.
            'x' => round(($layout[$n]['col'] + .5) / ($maxCol + 1) * 100, 2),
            'y' => round(($layout[$n]['lane'] + .5) / $lanes * 100, 2),
            'here' => $n === $here,
            'allies' => (int) ($allies[$n] ?? 0),
            'move' => in_array($n, $moves, true),
            'known' => in_array($n, $known, true) || ($traps[$n] ?? null) === 'found',
        ], $seen);

        // An edge is drawn when the runner knows one of its ends: that is what standing on a node or an Nmap shows.
        $links = $mapped ? $tree->edges : array_values(array_filter($tree->edges, static fn (array $e): bool => in_array($e[0], $known, true) || in_array($e[1], $known, true)));

        $ids = $scan !== null && $scan->ids_detection !== null ? (float) $system->ids_detection : null;

        return new self(
            $nodes,
            $links,
            $maxCol + 1,
            $lanes,
            $tree->isExit($here),
            $here === $tree->core,
            $simulator->refusal($character, $run, RunAction::Nmap, null) === null,
            $simulator->noiseLevel($run),
            $ids,
            $simulator->firewallClosed($run)
                ? $simulator->osChanceFor($character, $system->operatingSystem()->first())
                : null,
            $tree->nodes[$here]['kind'] === NodeKind::Vault,
            $simulator->hubOpen($run) ? $simulator->osChanceFor($character, $system->operatingSystem()->first()) : null,
            $simulator->hubSubverted($run),
            $tree->nodes[$here]['kind'] === NodeKind::Relay && $run->loopback === null,
            $tree->nodes[$here]['kind'] !== NodeKind::Relay ? null : (static function () use ($simulator, $character, $run): ?float {
                $strength = $run->loopback !== null
                    ? InventoryItem::query()->with('item.program')->find($run->loopback)?->item?->program?->loopback_strength
                    : $simulator->loopbackToHand($character)?->item?->program?->loopback_strength;

                return $strength === null ? null : $simulator->loopbackChance($character, $run, (int) $strength);
            })(),
            $run->loopback !== null,
            $disarm = array_values(array_map(
                static fn (int $n): array => [$n, self::label($tree, $n, true)],
                array_filter($tree->neighbours($here), static fn (int $n): bool => ($traps[$n] ?? null) === 'found' && $simulator->liveTrap($run, $n) !== null),
            )),
            $disarm !== [] && $system->os_skill_id !== null ? $simulator->osChanceFor($character, $system->operatingSystem()->first()) : null,
        );
    }

    /**
     * What a scan read of a system's tree, drawn outside a run: the whole shape once it read the Layout (`$read` 1),
     * every node named once it read the Nodes (2), and the traps once it read the Traps (3; until then they read as
     * conduits). Null when there is nothing to draw: no tree here, or not read.
     */
    public static function ofScan(?System $system, ?int $read): ?self
    {
        if ($system === null || $read === null || $read < 1 || ! RunTree::appliesTo($system)) {
            return null;
        }

        $tree = RunTree::of($system);
        $layout = $tree->layout();
        $maxCol = max(array_column($layout, 'col'));
        $lanes = $tree->lanes();

        $nodes = array_map(static fn (int $n): array => [
            'key' => $n,
            'label' => $read >= 2 ? self::label($tree, $n, $read >= 3) : '?',
            'x' => round(($layout[$n]['col'] + .5) / ($maxCol + 1) * 100, 2),
            'y' => round(($layout[$n]['lane'] + .5) / $lanes * 100, 2),
            'here' => false,
            'allies' => 0,
            'move' => false,
            'known' => $read >= 2,
        ], array_keys($tree->nodes));

        return new self($nodes, $tree->edges, $maxCol + 1, $lanes, false, false, false, 0.0, null);
    }
}
