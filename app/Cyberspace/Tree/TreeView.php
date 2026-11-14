<?php

declare(strict_types=1);

namespace App\Cyberspace\Tree;

use App\Cyberspace\RunAction;
use App\Cyberspace\Simulator;
use App\Models\Character;
use App\Models\Run;
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
        public bool $atEntry,
        public bool $atCore,
        public bool $canNmap,
        public float $noise,
        // The bar's depth when the runner's scan read the IDS (0: it has none); null when it did not.
        public ?float $ids,
        // Standing on a firewall that is still closed: the chance its OS roll lands (the core's own figure). Null otherwise.
        public ?float $firewallChance = null,
        // Standing on a data vault: it takes an Extract on any purpose.
        public bool $atVault = false,
    ) {}

    public static function for(Run $run, Character $character, Simulator $simulator, ?SystemScan $scan): ?self
    {
        $system = $run->system();

        if (! $run->isTreeRun() || $system === null) {
            return null;
        }

        $tree = RunTree::of($system);
        $here = (int) $run->node;
        $known = $run->knownNodes();
        $seen = $known;

        foreach ($known as $node) {
            $seen = [...$seen, ...$tree->neighbours($node)];
        }

        // A scan that read the Layout showed the whole shape before the run began.
        $mapped = $run->mapped();
        $seen = $mapped ? array_keys($tree->nodes) : array_values(array_unique($seen));
        $layout = $tree->layout();
        $maxCol = max(array_map(static fn (int $n): int => $layout[$n]['col'], $seen));
        $lanes = $tree->lanes();
        $allies = $run->run_party_id === null ? [] : Run::query()->live()->where('run_party_id', $run->run_party_id)
            ->whereKeyNot($run->getKey())->pluck('node')->countBy()->all();
        $moves = $tree->neighbours($here);

        $nodes = array_map(static fn (int $n): array => [
            'key' => $n,
            'label' => in_array($n, $known, true) ? Text::get('cyberspace.tree.node.'.$tree->nodes[$n]['kind']->value) : '?',
            // Centred in its own column, so the first and last boxes sit half a column in from the edges.
            'x' => round(($layout[$n]['col'] + .5) / ($maxCol + 1) * 100, 2),
            'y' => round(($layout[$n]['lane'] + .5) / $lanes * 100, 2),
            'here' => $n === $here,
            'allies' => (int) ($allies[$n] ?? 0),
            'move' => in_array($n, $moves, true),
            'known' => in_array($n, $known, true),
        ], $seen);

        // An edge is drawn when the runner knows one of its ends: that is what standing on a node or an Nmap shows.
        $links = $mapped ? $tree->edges : array_values(array_filter($tree->edges, static fn (array $e): bool => in_array($e[0], $known, true) || in_array($e[1], $known, true)));

        $ids = $scan !== null && $scan->ids_detection !== null ? (float) $system->ids_detection : null;

        return new self(
            $nodes,
            $links,
            $maxCol + 1,
            $lanes,
            $here === $tree->entry,
            $here === $tree->core,
            $simulator->refusal($character, $run, RunAction::Nmap, null) === null,
            $simulator->noiseLevel($run),
            $ids,
            $tree->nodes[$here]['kind'] === NodeKind::Firewall && ! in_array($here, $run->openedFirewalls(), true) && $system->os_skill_id !== null
                ? $simulator->osChanceFor($character, $system->operatingSystem()->first())
                : null,
            $tree->nodes[$here]['kind'] === NodeKind::Vault,
        );
    }
}
