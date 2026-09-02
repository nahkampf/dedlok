<?php

namespace Tests\Unit\Skills;

use App\Skills\PrerequisiteGraph;
use PHPUnit\Framework\TestCase;

/**
 * The graph walk, on its own.
 *
 * Pure ids and no framework, which is the point of the class: the admin editor
 * has to ask what WOULD happen if a set of edges were saved, and that question
 * cannot be answered by anything that needs the rows to exist first.
 */
class PrerequisiteGraphTest extends TestCase
{
    /** @param  array<int, list<int>>  $edges */
    private function graph(array $edges): PrerequisiteGraph
    {
        $rows = [];

        foreach ($edges as $skill => $requires) {
            foreach ($requires as $required) {
                $rows[] = ['skill_id' => $skill, 'required_skill_id' => $required];
            }
        }

        return PrerequisiteGraph::fromRows($rows);
    }

    public function test_an_empty_graph_is_acyclic(): void
    {
        $this->assertTrue($this->graph([])->isAcyclic());
    }

    public function test_a_chain_is_acyclic(): void
    {
        $this->assertTrue($this->graph([3 => [2], 2 => [1]])->isAcyclic());
    }

    /** Two paths to the same root is a diamond, not a loop. */
    public function test_a_diamond_is_acyclic(): void
    {
        $this->assertTrue($this->graph([4 => [2, 3], 2 => [1], 3 => [1]])->isAcyclic());
    }

    public function test_it_finds_a_two_step_cycle(): void
    {
        $graph = $this->graph([1 => [2], 2 => [1]]);

        $this->assertFalse($graph->isAcyclic());
        $this->assertNotEmpty($graph->cycleThrough(1));
    }

    public function test_it_finds_a_longer_cycle(): void
    {
        $graph = $this->graph([1 => [2], 2 => [3], 3 => [1]]);

        $cycle = $graph->cycleThrough(2);

        $this->assertNotEmpty($cycle);
        $this->assertContains(3, $cycle);
    }

    /** A skill outside the loop is not reported as being in it. */
    public function test_a_node_outside_a_cycle_is_not_named(): void
    {
        $graph = $this->graph([1 => [2], 2 => [1], 9 => [1]]);

        $this->assertFalse($graph->isAcyclic());
        $this->assertSame([], $graph->cycleThrough(9));
    }

    /**
     * The question the editor actually asks: not "may I add this edge" but
     * "would the graph still be sound with these edges in place of the ones
     * that are there now".
     */
    public function test_with_replaces_a_skills_requirements(): void
    {
        $graph = $this->graph([2 => [1]]);

        // 1 now requires 2, and 2 requires 1. That closes the loop.
        $this->assertFalse($graph->with(1, [2])->isAcyclic());

        // Replacing 2's requirement with nothing opens it again.
        $this->assertTrue($graph->with(1, [2])->with(2, [])->isAcyclic());
    }

    public function test_with_does_not_mutate_the_original(): void
    {
        $graph = $this->graph([2 => [1]]);

        $graph->with(1, [2]);

        $this->assertTrue($graph->isAcyclic());
    }

    public function test_a_self_edge_is_a_cycle(): void
    {
        $this->assertFalse($this->graph([1 => [1]])->isAcyclic());
    }
}
