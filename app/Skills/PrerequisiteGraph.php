<?php

declare(strict_types=1);

namespace App\Skills;

/**
 * The prerequisite graph, and the one question that cannot be asked of a
 * single row: does it contain a cycle?
 *
 * A cycle is a skill nobody can ever learn, and every edge in it looks
 * perfectly reasonable on its own — which is why this has to be checked
 * against the whole graph, both by `skills:validate` after the fact and by the
 * admin editor before an edge is written at all.
 *
 * Pure: it holds ids, never models, so the editor can ask what WOULD happen if
 * a set of edges were saved without saving them first.
 */
final class PrerequisiteGraph
{
    /**
     * @param  array<int, list<int>>  $edges  skill id => the skills it requires
     */
    public function __construct(private readonly array $edges = []) {}

    /**
     * @param  iterable<array{skill_id: int|string, required_skill_id: int|string}>  $rows
     */
    public static function fromRows(iterable $rows): self
    {
        $edges = [];

        foreach ($rows as $row) {
            $edges[(int) $row['skill_id']][] = (int) $row['required_skill_id'];
        }

        return new self($edges);
    }

    /**
     * The same graph with one skill's requirements replaced wholesale.
     *
     * The editor saves a skill's prerequisites as a set, so this is exactly
     * the shape of the question it needs to ask: not "may I add this edge"
     * but "would the graph still be sound with these edges in place of the
     * ones that are there now".
     *
     * @param  list<int>  $requires
     */
    public function with(int $skillId, array $requires): self
    {
        $edges = $this->edges;
        $edges[$skillId] = array_values(array_unique(array_map('intval', $requires)));

        return new self($edges);
    }

    /**
     * Every cycle reachable in the graph, each as the list of ids that closes
     * it — so a caller can name the loop rather than only report that one is
     * there.
     *
     * Depth-first with a three-state colouring: unvisited, on the current
     * stack, finished. Meeting a node that is still on the stack IS the cycle,
     * and the stack from that node down is its path.
     *
     * @return list<list<int>>
     */
    public function cycles(): array
    {
        $found = [];
        $state = [];
        $stack = [];

        $visit = function (int $id) use (&$visit, &$state, &$stack, &$found): void {
            $colour = $state[$id] ?? 0;

            if ($colour === 2) {
                return;
            }

            if ($colour === 1) {
                $position = array_search($id, $stack, true);
                $cycle = array_slice($stack, $position === false ? 0 : $position);
                $cycle[] = $id;

                $found[] = array_values($cycle);

                return;
            }

            $state[$id] = 1;
            $stack[] = $id;

            foreach ($this->edges[$id] ?? [] as $next) {
                $visit($next);
            }

            array_pop($stack);
            $state[$id] = 2;
        };

        foreach ($this->nodes() as $id) {
            $visit($id);
        }

        return $found;
    }

    /** Is every skill in this graph reachable — that is, is it acyclic? */
    public function isAcyclic(): bool
    {
        return $this->cycles() === [];
    }

    /**
     * The cycle a given skill sits in, if any. What the editor reports: the
     * admin changed one skill's requirements, so that is the loop worth
     * naming even when the graph already had others.
     *
     * @return list<int>
     */
    public function cycleThrough(int $skillId): array
    {
        foreach ($this->cycles() as $cycle) {
            if (in_array($skillId, $cycle, true)) {
                return $cycle;
            }
        }

        return [];
    }

    /**
     * Every skill the graph mentions, on either end of an edge.
     *
     * Nodes with no edges at all are absent, and that is correct: a skill
     * nothing requires and which requires nothing cannot be in a cycle.
     *
     * @return list<int>
     */
    public function nodes(): array
    {
        $nodes = array_keys($this->edges);

        foreach ($this->edges as $requires) {
            foreach ($requires as $id) {
                $nodes[] = $id;
            }
        }

        return array_values(array_unique(array_map('intval', $nodes)));
    }
}
