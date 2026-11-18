<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\SystemType;
use App\Cyberspace\Tree\NodeKind;
use App\Cyberspace\Tree\RunTree;
use PHPUnit\Framework\TestCase;

final class TripgateTest extends TestCase
{
    /** @return iterable<int, array{0: string}> */
    private static function addresses(): iterable
    {
        for ($i = 0; $i < 300; $i++) {
            yield [sprintf('%08X', crc32("gate-{$i}"))];
        }
    }

    public function test_gates_come_from_grade_5_one_or_two_and_never_strand_anybody(): void
    {
        $seen = 0;

        foreach (self::addresses() as [$address]) {
            foreach ([1, 4] as $grade) {
                $this->assertSame([], RunTree::generate($address, $grade, SystemType::Compute)->nodesOf(NodeKind::Tripgate));
            }

            foreach ([5, 7, 9] as $grade) {
                foreach ([SystemType::Silo, SystemType::Compute, SystemType::Relay] as $type) {
                    $tree = RunTree::generate($address, $grade, $type);
                    $gates = $tree->nodesOf(NodeKind::Tripgate);
                    $seen += count($gates);

                    $this->assertLessThanOrEqual(2, count($gates));
                    $this->assertTrue($tree->sound(), "{$address} g{$grade}: a gate left somebody without a way out");

                    foreach ($gates as $gate) {
                        [$from, $to] = [$tree->nodes[$gate]['from'], $tree->nodes[$gate]['to']];
                        $this->assertNotContains($tree->nodes[$gate]['path'], ['fast', 'main']);
                        $this->assertTrue($tree->canStep($from, $gate) && $tree->canStep($gate, $to));
                        $this->assertFalse($tree->canStep($to, $gate), 'entered from the far side');
                        $this->assertFalse($tree->canStep($gate, $from), 'left by the near side');
                    }
                }
            }
        }

        $this->assertGreaterThan(100, $seen, 'gates actually appear');
    }

    public function test_a_cut_leaves_the_gates_as_they_are(): void
    {
        foreach (self::addresses() as [$address]) {
            $tree = RunTree::generate($address, 9, SystemType::Compute);
            $gates = $tree->nodesOf(NodeKind::Tripgate);

            if ($gates !== []) {
                $this->assertSame($gates, $tree->cut([$tree->entry + 1])->nodesOf(NodeKind::Tripgate));

                return;
            }
        }

        $this->fail('no tree with a gate in 300 addresses');
    }
}
