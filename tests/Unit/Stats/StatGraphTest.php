<?php

declare(strict_types=1);

namespace Tests\Unit\Stats;

use App\Stats\StatGraph;
use PHPUnit\Framework\TestCase;

/**
 * The order derived stats can be resolved in, and the declaration errors that
 * have to fail loudly rather than late.
 *
 * Pure: stat ids and nothing else, so this needs no database and no config.
 */
final class StatGraphTest extends TestCase
{
    public function test_inputs_come_before_what_reads_them(): void
    {
        $graph = new StatGraph([
            'hitpoints_max' => ['meat', 'muscle', 'focus'],
            'check.intrusion' => ['skill.intrusion', 'perception'],
        ]);

        $order = $graph->order();

        foreach (['meat', 'muscle', 'focus'] as $input) {
            $this->assertLessThan(
                array_search('hitpoints_max', $order, true),
                array_search($input, $order, true),
                "[{$input}] must be resolved before the stat that reads it"
            );
        }
    }

    public function test_a_stored_stat_is_not_derived_and_reads_nothing(): void
    {
        $graph = new StatGraph(['hitpoints_max' => ['meat']]);

        $this->assertTrue($graph->isDerived('hitpoints_max'));
        $this->assertFalse($graph->isDerived('meat'));
        $this->assertSame([], $graph->inputsFor('meat'));
    }

    /**
     * Asking for one stat resolves what it reads and nothing else — a screen
     * wanting `check.intrusion` must not resolve every other check in the game.
     */
    public function test_order_for_one_stat_is_only_what_that_stat_needs(): void
    {
        $graph = new StatGraph([
            'hitpoints_max' => ['meat', 'muscle'],
            'check.intrusion' => ['skill.intrusion', 'perception'],
            'check.ballistics' => ['skill.ballistics', 'senses'],
        ]);

        $needed = $graph->orderFor('check.intrusion');

        $this->assertContains('skill.intrusion', $needed);
        $this->assertContains('perception', $needed);
        $this->assertContains('check.intrusion', $needed);
        $this->assertNotContains('check.ballistics', $needed);
        $this->assertNotContains('hitpoints_max', $needed);
    }

    public function test_a_chain_resolves_deepest_first(): void
    {
        $graph = new StatGraph(['c' => ['b'], 'b' => ['a']]);

        $this->assertSame(['a', 'b', 'c'], $graph->orderFor('c'));
    }

    /** A stat read by two others is resolved once, not twice. */
    public function test_a_shared_input_appears_once(): void
    {
        $graph = new StatGraph(['hp' => ['meat', 'focus'], 'si' => ['focus', 'memory']]);

        $order = $graph->order();

        $this->assertCount(1, array_keys($order, 'focus', true));
    }

    /**
     * A cycle is a declaration error, and it has to name both halves: it is
     * never wrong on either row on its own, only together.
     */
    public function test_a_cycle_is_refused_and_named(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/cycle: .*a.*b.*a/');

        (new StatGraph(['a' => ['b'], 'b' => ['a']]))->order();
    }

    public function test_a_longer_cycle_is_found(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/cycle/');

        (new StatGraph(['a' => ['b'], 'b' => ['c'], 'c' => ['a']]))->order();
    }

    public function test_a_stat_that_reads_itself_is_a_cycle(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new StatGraph(['a' => ['a']]))->order();
    }

    public function test_an_empty_graph_answers_rather_than_breaks(): void
    {
        $graph = new StatGraph;

        $this->assertSame([], $graph->order());
        $this->assertSame(['focus'], $graph->orderFor('focus'));
    }

    public function test_a_repeated_input_is_only_declared_once(): void
    {
        $graph = new StatGraph(['check.x' => ['focus', 'focus', 'skill.x']]);

        $this->assertSame(['focus', 'skill.x'], $graph->inputsFor('check.x'));
    }
}
