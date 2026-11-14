<?php

declare(strict_types=1);

namespace Tests\Unit\Cyberspace;

use App\Cyberspace\SystemType;
use App\Cyberspace\Tree\NodeKind;
use App\Cyberspace\Tree\RunTree;
use App\Cyberspace\Tree\TrapEffect;
use PHPUnit\Framework\TestCase;

final class RunTreeTest extends TestCase
{
    private const TYPES = [SystemType::Silo, SystemType::Compute, SystemType::Relay];

    public function test_the_same_address_always_gives_the_same_tree(): void
    {
        $this->assertEquals(RunTree::generate('DEADBEEF', 7, SystemType::Silo), RunTree::generate('DEADBEEF', 7, SystemType::Silo));
        $this->assertNotEquals(RunTree::generate('DEADBEEF', 7, SystemType::Silo), RunTree::generate('DEADBEF0', 7, SystemType::Silo));
    }

    public function test_grade_1_is_the_entry_then_the_core(): void
    {
        foreach ($this->addresses(50) as $address) {
            $tree = RunTree::generate($address, 1, SystemType::Compute);

            $this->assertCount(2, $tree->nodes);
            $this->assertSame([[$tree->entry, $tree->core]], $tree->edges);
        }
    }

    public function test_grade_2_has_one_node_between_a_vault_or_a_conduit_and_a_silo_always_a_vault(): void
    {
        $seen = [];

        foreach ($this->addresses(200) as $address) {
            $tree = RunTree::generate($address, 2, SystemType::Compute);
            $this->assertCount(3, $tree->nodes);
            $this->assertSame(2, $tree->distance($tree->entry, $tree->core));
            $seen[$tree->nodes[2]['kind']->value] = true;

            $this->assertCount(1, RunTree::generate($address, 2, SystemType::Silo)->nodesOf(NodeKind::Vault));
        }

        $this->assertEqualsCanonicalizing(['vault', 'conduit'], array_keys($seen));
    }

    public function test_the_rules_hold_for_every_grade_and_type(): void
    {
        foreach (range(1, 9) as $grade) {
            foreach (self::TYPES as $type) {
                foreach ($this->addresses(150) as $address) {
                    $this->assertRules(RunTree::generate($address, $grade, $type), $grade, $type, $address);
                }
            }
        }
    }

    private function assertRules(RunTree $tree, int $grade, SystemType $type, string $address): void
    {
        $why = "{$address} grade {$grade} {$type->value}";

        $this->assertSame(NodeKind::Entry, $tree->nodes[$tree->entry]['kind'], $why);
        $this->assertSame(NodeKind::Core, $tree->nodes[$tree->core]['kind'], $why);
        $this->assertCount(1, $tree->nodesOf(NodeKind::Entry), $why);
        $this->assertCount(1, $tree->nodesOf(NodeKind::Core), $why);

        // Every node is reachable, so nothing is drawn that cannot be walked to.
        foreach (array_keys($tree->nodes) as $node) {
            $this->assertNotNull($tree->distance($tree->entry, $node), $why);
        }

        $paths = array_unique(array_filter(array_column($tree->nodes, 'path')));
        $expected = match (true) {
            $grade === 1 => [0],
            $grade === 2 => [1],
            $grade <= 4 => [2],
            $grade <= 6 => [2, 3],
            default => [3],
        };
        $this->assertContains(count($paths), $expected, $why);

        $firewalls = $tree->nodesOf(NodeKind::Firewall);

        if ($grade < 3) {
            $this->assertSame([], $firewalls, $why);
        } else {
            // One per path; the fast path may hold a second from grade 7.
            $fast = array_filter($firewalls, static fn (int $n): bool => $tree->nodes[$n]['path'] === 'fast');
            $this->assertCount(count($paths), array_unique(array_map(static fn (int $n): string => (string) $tree->nodes[$n]['path'], $firewalls)), $why);
            $this->assertLessThanOrEqual($grade >= 7 ? 2 : 1, count($fast), $why);
            $this->assertLessThanOrEqual(count($paths) + ($grade >= 7 ? 1 : 0), count($firewalls), $why);
        }

        if ($type === SystemType::Silo && $grade >= 2) {
            $this->assertNotEmpty($tree->nodesOf(NodeKind::Vault), $why);
        }

        // A maintenance port from grade 3 wherever there is a rich path: one, a dead end, hanging off a rich node that
        // is not a firewall; and it never shortens the way to the core.
        $ports = $tree->nodesOf(NodeKind::Port);
        $rich = in_array('rich', $paths, true);
        $this->assertCount($grade >= 3 && $rich ? 1 : 0, $ports, $why);

        foreach ($ports as $port) {
            $this->assertTrue($tree->isExit($port), $why);
            $this->assertCount(1, $tree->neighbours($port), $why);
            $from = $tree->nodes[$tree->neighbours($port)[0]];
            $this->assertSame('rich', $from['path'], $why);
            $this->assertContains($from['kind'], [NodeKind::Switch, NodeKind::Vault], $why);
        }

        // Internal relays only on the quiet path from grade 3: one, two at most on a relay system.
        foreach ($tree->nodesOf(NodeKind::Relay) as $relay) {
            $this->assertSame('quiet', $tree->nodes[$relay]['path'], $why);
        }

        $this->assertLessThanOrEqual($grade < 3 ? 0 : ($type === SystemType::Relay ? 2 : 1), count($tree->nodesOf(NodeKind::Relay)), $why);

        // Traps from grade 4, never on the fast path, each with its effect.
        foreach ($tree->nodesOf(NodeKind::Trap) as $trap) {
            $this->assertGreaterThanOrEqual(4, $grade, $why);
            $this->assertContains($tree->nodes[$trap]['path'], ['quiet', 'rich'], $why);
            $this->assertArrayHasKey('trap', $tree->nodes[$trap], $why);
        }

        // At most one log server, from grade 5: a dead end off a switch of the quiet or rich path.
        $this->assertLessThanOrEqual($grade >= 5 ? 1 : 0, count($tree->nodesOf(NodeKind::Log)), $why);

        foreach ($tree->nodesOf(NodeKind::Log) as $log) {
            $this->assertCount(1, $tree->neighbours($log), $why);
            $from = $tree->nodes[$tree->neighbours($log)[0]];
            $this->assertSame(NodeKind::Switch, $from['kind'], $why);
            $this->assertContains($from['path'], ['quiet', 'rich'], $why);
        }

        // Honeypots from grade 5, never a Node Disconnect, never the tree's first vault.
        $vaults = $tree->nodesOf(NodeKind::Vault);

        foreach ($vaults as $i => $vault) {
            if ($tree->isHoneypot($vault)) {
                $this->assertGreaterThanOrEqual(5, $grade, $why);
                $this->assertGreaterThan(0, $i, $why);
                $this->assertNotSame(TrapEffect::Disconnect, $tree->nodes[$vault]['trap'], $why);
            }
        }

        // The quiet path is the long one: about 5 moves at grade 5 and 7 at grade 9 when nothing cuts across.
        $this->assertLessThanOrEqual(2 + (int) round(($grade - 1) * 7 / 8) - 1, $tree->distance($tree->entry, $tree->core), $why);
    }

    /** @return list<string> */
    private function addresses(int $count): array
    {
        return array_map(static fn (int $i): string => strtoupper(str_pad(dechex(crc32("tree-{$i}")), 8, '0', STR_PAD_LEFT)), range(1, $count));
    }
}
