<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\BackdoorKind;
use App\Cyberspace\Backdoors;
use App\Cyberspace\RunPurpose;
use App\Models\Character;
use App\Models\Run;
use App\Models\System;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Trace panel draws every hop of the run's route, not just the two ends.
 */
final class TraceChainTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_chain_is_the_machine_every_hop_and_you(): void
    {
        $character = Character::factory()->create();
        $hops = collect([1, 2, 3])->map(fn (int $n): System => System::factory()->at($n, $n)->create());

        foreach ($hops->take(2) as $hop) {
            (new Backdoors)->install($character, $hop, BackdoorKind::Routing);
        }

        // The third is on the route but no longer held: it keeps its place, as a gap.
        $run = Run::query()->create([
            'character_id' => $character->id, 'address' => 'DEADBEEF', 'x' => 9, 'y' => 9, 'purpose' => RunPurpose::Extraction,
            'chain' => $hops->pluck('id')->all(), 'trace_hop' => 1, 'trace' => 40, 'started_at' => now(),
        ]);

        $nodes = $run->traceChain();

        $this->assertSame(['target', 'hop', 'hop', 'hop', 'you'], array_column($nodes, 'kind'));
        $this->assertSame([true, true, false, false, false], array_column($nodes, 'passed'), 'one hop is behind the trace');
        $this->assertSame([false, false, false, true, false], array_column($nodes, 'gone'));
        $this->assertSame($hops[0]->address, $nodes[1]['label']);
    }

    public function test_a_run_without_routing_is_the_machine_and_you(): void
    {
        $run = Run::query()->create([
            'character_id' => Character::factory()->create()->id, 'address' => 'DEADBEEF', 'x' => 9, 'y' => 9,
            'purpose' => RunPurpose::Extraction, 'trace' => 100, 'started_at' => now(),
        ]);

        $this->assertSame([true, true], array_column($run->traceChain(), 'passed'));
        $this->assertCount(2, $run->traceChain());
    }
}
