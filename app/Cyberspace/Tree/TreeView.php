<?php

declare(strict_types=1);

namespace App\Cyberspace\Tree;

use App\Cyberspace\RunAction;
use App\Cyberspace\Simulator;
use App\Models\Character;
use App\Models\Ice;
use App\Models\InventoryItem;
use App\Models\Run;
use App\Models\System;
use App\Models\SystemScan;
use App\Support\Text;
use Illuminate\Support\Collection;

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
     * @param  list<array{key: int, label: string, x: float, y: float, here: bool, allies: int, sentries: int, cutting: ?int, move: bool, known: bool, threat?: bool, kind?: ?string, sprung?: bool, ice?: bool, barrier?: bool}>  $nodes
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
        // Standing on a log server with an alert to wipe: the chance the OS roll lands. Null otherwise.
        public ?float $wipeChance = null,
        /** @var list<array{name: string, integrity: float, awake: bool}> The unbroken sentries on the runner's node. */
        public array $sentriesHere = [],
        // Standing on the ICE controller while it runs: the chance a Shut down's OS roll lands. Null otherwise.
        public ?float $shutdownChance = null,
        // An Egress Tunnel may be started here (not the core, none of theirs up, a script and the skill); theirs is up.
        public bool $canTunnel = false,
        public bool $tunnelling = false,
    ) {}

    /** What a node is called on the map: a trap reads as a conduit until it is found, and as one again once spent. */
    private static function label(RunTree $tree, int $node, bool $found): string
    {
        $kind = $tree->nodes[$node]['kind'];

        if ($kind === NodeKind::Trap && $found) {
            return Text::get('cyberspace.tree.trap_label', ['effect' => $tree->nodes[$node]['trap']->label()]);
        }

        // A honeypot is a vault to everybody but a deep Nmap.
        if ($tree->isHoneypot($node) && $found) {
            return Text::get('cyberspace.tree.honeypot_label', ['effect' => $tree->nodes[$node]['trap']->label()]);
        }

        return Text::get('cyberspace.tree.node.'.($kind === NodeKind::Trap ? NodeKind::Conduit : $kind)->value);
    }

    /**
     * How many nodes of each kind a scan's Nodes read, by the kinds' names, in the enum's order: a trap counted as a
     * conduit and a honeypot as a vault, as the reading shows them.
     *
     * @return array<string, int>
     */
    public static function kindCounts(RunTree $tree): array
    {
        $counts = array_count_values(array_map(static fn (int $n): string => self::kindShown($tree, $n, false), array_keys($tree->nodes)));
        $out = [];

        foreach (NodeKind::cases() as $kind) {
            if (($counts[$kind->value] ?? 0) > 0) {
                $out[Text::get('cyberspace.tree.node.'.$kind->value)] = $counts[$kind->value];
            }
        }

        return $out;
    }

    /** The kind the map colours a named node by: what its label says (a hidden trap is a conduit, a honeypot a vault). */
    private static function kindShown(RunTree $tree, int $node, bool $found): string
    {
        $kind = $tree->nodes[$node]['kind'];

        return ($kind === NodeKind::Trap && ! $found ? NodeKind::Conduit : $kind)->value;
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
        // The chance the OS roll lands, for every action that rolls it: asked once, when one is shown.
        $osSkill = false;
        $osChance = static function () use (&$osSkill, $simulator, $character, $system): float {
            $osSkill = $osSkill === false ? $system->operatingSystem()->first() : $osSkill;

            return $simulator->osChanceFor($character, $osSkill);
        };
        $iceNames = Ice::query()->whereKey(array_column($simulator->sentriesOf($run), 'ice'))->pluck('name', 'id');
        // Never empty: where they stand is always drawn, even on a node the tree has lost.
        $seen = $seen === [] ? [$here] : $seen;
        $maxCol = max(array_map(static fn (int $n): int => $layout[$n]['col'], $seen));
        $lanes = $tree->lanes();
        $allies = $run->run_party_id === null ? [] : Run::query()->live()->where('run_party_id', $run->run_party_id)
            ->whereKeyNot($run->getKey())->pluck('node')->countBy()->all();
        $moves = $tree->neighbours($here);
        // Sentries are seen where the runner can see: a node they know.
        /** @var Collection<int, array<string, mixed>> $standing */
        $standing = collect($simulator->sentriesOf($run))->filter(static fn (array $s): bool => (float) $s['integrity'] > 0);
        $guards = $standing->reject(static fn (array $s): bool => (bool) ($s['barrier'] ?? false))->countBy('node')->all();
        $barriers = $standing->filter(static fn (array $s): bool => (bool) ($s['barrier'] ?? false))->countBy('node')->all();
        // THREATS, drawn in ember where the runner knows them: a security hub nobody has subverted, a running ICE
        // controller, the core while its ICE stands, and any node with a sentry on it.
        $coreIce = $system->ice_id !== null && ! ($run->ice_id === null && (float) $run->ice_damage > 0);
        $subverted = $simulator->hubSubverted($run);
        $controllerUp = is_array($run->controller) && ! $run->controller['down'];
        $threat = static fn (int $n): bool => match ($tree->nodes[$n]['kind']) {
            NodeKind::Hub => ! $subverted,
            NodeKind::Controller => $controllerUp,
            NodeKind::Core => $coreIce,
            default => false,
            // A vault keeps its violet: a sentry posted on one shows by its ▲ alone (decided 2026-11-14).
        } || ((($guards[$n] ?? 0) > 0 || ($barriers[$n] ?? 0) > 0) && $tree->nodes[$n]['kind'] !== NodeKind::Vault);
        $shown = static fn (int $n): bool => in_array($traps[$n] ?? null, ['found', 'sprung'], true);
        // A Cordon cutting a node shows its countdown wherever the runner can see the node: that is the warning.
        $cutting = collect($simulator->sentriesOf($run))->filter(static fn (array $s): bool => (float) $s['integrity'] > 0 && ($s['countdown'] ?? null) !== null)
            ->mapWithKeys(static fn (array $s): array => [(int) $s['node'] => (int) $s['countdown']])->all();

        // A firewall nobody has breached, or barrier ICE standing, lets them back the way they came, and no further.
        if ($simulator->firewallClosed($run) || $simulator->barrierHolds($run)) {
            $moves = array_values(array_filter($moves, static fn (int $n): bool => ! in_array([$here, $n], $tree->edges, true)));
        }

        $nodes = array_map(static fn (int $n): array => [
            'key' => $n,
            // A trap shows itself once found, and once it has gone off.
            'label' => in_array($n, $known, true) || $shown($n) ? self::label($tree, $n, $shown($n)) : '?',
            // Centred in its own column, so the first and last boxes sit half a column in from the edges.
            'x' => round(($layout[$n]['col'] + .5) / ($maxCol + 1) * 100, 2),
            'y' => round(($layout[$n]['lane'] + .5) / $lanes * 100, 2),
            'here' => $n === $here,
            'allies' => (int) ($allies[$n] ?? 0),
            'sentries' => in_array($n, $known, true) ? (int) ($guards[$n] ?? 0) : 0,
            'barrier' => in_array($n, $known, true) && ($barriers[$n] ?? 0) > 0,
            'cutting' => $cutting[$n] ?? null,
            'move' => in_array($n, $moves, true),
            'known' => in_array($n, $known, true) || $shown($n),
            'threat' => in_array($n, $known, true) && $threat($n),
            'kind' => in_array($n, $known, true) || $shown($n) ? self::kindShown($tree, $n, $shown($n)) : null,
            'sprung' => ($traps[$n] ?? null) === 'sprung',
            // The system's own ICE, on the core while it stands.
            'ice' => in_array($n, $known, true) && $tree->nodes[$n]['kind'] === NodeKind::Core && $coreIce,
        ], $seen);

        // An edge is drawn when the runner knows one of its ends: that is what standing on a node or an Nmap shows.
        $links = $mapped ? $tree->edges : array_values(array_filter($tree->edges, static fn (array $e): bool => in_array($e[0], $known, true) || in_array($e[1], $known, true)));

        $ids = $scan !== null && $scan->ids_detection !== null ? (float) $system->ids_detection : null;

        return new self(
            $nodes,
            $links,
            $maxCol + 1,
            $lanes,
            $simulator->atExit($run),
            $here === $tree->core,
            $simulator->refusal($character, $run, RunAction::Nmap, null) === null,
            $simulator->noiseLevel($run),
            $ids,
            $simulator->firewallClosed($run)
                ? $osChance()
                : null,
            $tree->nodes[$here]['kind'] === NodeKind::Vault,
            $simulator->hubOpen($run) ? $osChance() : null,
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
                array_filter([...$tree->neighbours($here), ...($tree->isHoneypot($here) ? [$here] : [])], static fn (int $n): bool => ($traps[$n] ?? null) === 'found' && $simulator->liveTrap($run, $n) !== null),
            )),
            $disarm !== [] && $system->os_skill_id !== null ? $osChance() : null,
            $simulator->logOpen($run) ? $osChance() : null,
            array_values(array_map(static fn (array $s): array => [
                // Read by a deep Nmap: whether it is Fast, and whether it carries the Cordon.
                'name' => (string) ($iceNames[$s['ice']] ?? '').(($s['read'] ?? false)
                    ? ' ('.implode(', ', [Text::get($s['fast'] ? 'cyberspace.tree.sentry_fast' : 'cyberspace.tree.sentry_slow'), ...(($s['cordon'] ?? false) ? [Text::get('cyberspace.tree.sentry_cordon')] : [])]).')'
                    : ''),
                'integrity' => (float) $s['integrity'],
                'awake' => (bool) $s['awake'],
                'barrier' => (bool) ($s['barrier'] ?? false),
            ], $simulator->sentriesHere($run))),
            $simulator->controllerOpen($run) ? $osChance() : null,
            $simulator->refusal($character, $run, RunAction::Tunnel, null) === null,
            $run->tunnel !== null,
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
            // The Traps read names traps; a honeypot stays a vault to every scan (only Deep Nmap tells).
            'label' => $read >= 2 ? self::label($tree, $n, $read >= 3 && $tree->nodes[$n]['kind'] === NodeKind::Trap) : '?',
            'kind' => $read >= 2 ? self::kindShown($tree, $n, $read >= 3 && $tree->nodes[$n]['kind'] === NodeKind::Trap) : null,
            'x' => round(($layout[$n]['col'] + .5) / ($maxCol + 1) * 100, 2),
            'y' => round(($layout[$n]['lane'] + .5) / $lanes * 100, 2),
            'here' => false,
            'allies' => 0,
            'sentries' => 0,
            'cutting' => null,
            'move' => false,
            'known' => $read >= 2,
        ], array_keys($tree->nodes));

        return new self($nodes, $tree->edges, $maxCol + 1, $lanes, false, false, false, 0.0, null);
    }
}
