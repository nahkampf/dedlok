<?php

declare(strict_types=1);

namespace App\Cyberspace\Tree;

use App\Models\Character;
use App\Models\Run;
use App\Models\System;
use App\Support\Text;

/**
 * What a character has seen of a system's tree on their own runs there (and their parties' runs): the nodes and the traps
 * found. Read by the scan facts, so an explored system's Layout, Nodes and Traps say what the runs showed even without a
 * scan, merged with what a scan read (docs/RUN_TREE.md). The tree is drawn from the address and changes only when the
 * system relocates, which takes a new address: runs at this address saw this tree.
 */
final readonly class Explored
{
    /**
     * @param  list<int>  $nodes  the nodes seen
     * @param  list<int>  $traps  the traps found (found, sprung, spent or cut)
     */
    private function __construct(public RunTree $tree, public array $nodes, public array $traps) {}

    /** Null when the system walks no tree or the character never ran it. */
    public static function of(Character $character, string $address): ?self
    {
        $system = System::query()->where('address', $address)->first();

        if ($system === null || ! RunTree::appliesTo($system)) {
            return null;
        }

        $mine = Run::query()->where('address', $address)->whereNotNull('node')->where('character_id', $character->getKey());
        $runs = Run::query()->where('address', $address)->whereNotNull('node')
            ->where(fn ($q) => $q->whereIn('id', (clone $mine)->select('id'))
                ->orWhereIn('run_party_id', (clone $mine)->whereNotNull('run_party_id')->select('run_party_id')))
            ->get(['known', 'traps']);

        if ($runs->isEmpty()) {
            return null;
        }

        $tree = RunTree::of($system);
        $nodes = array_values(array_unique(array_map('intval', array_merge(...$runs->map(fn (Run $r): array => $r->known ?? [])->all()))));
        $traps = array_values(array_unique(array_map('intval', array_merge(...$runs->map(fn (Run $r): array => array_keys($r->traps ?? []))->all()))));

        return new self($tree, array_values(array_intersect($nodes, array_keys($tree->nodes))), $traps);
    }

    /** Every node of the tree seen: the shape and the nodes are then whole. */
    public function complete(): bool
    {
        return count($this->nodes) >= count($this->tree->nodes);
    }

    /** The paths the seen nodes lie on, and the moves to the core once the core has been seen. */
    public function layout(): string
    {
        $paths = Text::plural('cyberspace.reading.paths', count(array_unique(array_filter(array_map(fn (int $n): ?string => $this->tree->nodes[$n]['path'] ?? null, $this->nodes)))));

        return in_array($this->tree->core, $this->nodes, true)
            ? Text::get('cyberspace.reading.layout', ['paths' => $paths, 'moves' => Text::plural('cyberspace.reading.moves', (int) $this->tree->distance($this->tree->entry, $this->tree->core))])
            : Text::get('cyberspace.reading.layout_seen', ['paths' => $paths]);
    }

    public function nodeCount(): string
    {
        return Text::plural('cyberspace.reading.node_count', count($this->nodes));
    }

    public function trapsFound(): string
    {
        $traps = array_values(array_filter($this->traps, fn (int $n): bool => ($this->tree->nodes[$n]['kind'] ?? null) === NodeKind::Trap));

        return $traps === []
            ? Text::get('cyberspace.reading.traps_none_found')
            : Text::plural('cyberspace.reading.traps', count($traps), ['effects' => implode(', ', array_map(fn (int $n): string => $this->tree->nodes[$n]['trap']->label(), $traps))]);
    }
}
