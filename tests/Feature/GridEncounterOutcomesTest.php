<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\City\EncounterOutcomes;
use App\City\OutcomeBranch;
use App\City\OutcomeKind;
use App\Cyberspace\RunPurpose;
use App\Cyberspace\RunStarter;
use App\Models\Character;
use App\Models\Encounter;
use App\Models\EncounterNode;
use App\Models\EncounterOutcome;
use App\Models\Ice;
use App\Models\Run;
use App\Models\System;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/** The grid's outcome kinds: trace, wake_ice, detected and grid_move. Each through the writer that owns the thing. */
final class GridEncounterOutcomesTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    private Character $character;

    private int $made = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 16);
        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        $this->equipReadyDeck($this->character);
        $this->character->forceFill(['grid_x' => 8, 'grid_y' => 8])->save();
        $this->character = $this->character->fresh();
    }

    private function outcome(OutcomeKind $kind, ?float $amount = null, ?string $ref = null): EncounterOutcome
    {
        $encounter = Encounter::create(['slug' => 'o_'.$kind->value.'_'.(++$this->made), 'title' => 'x', 'starts_on' => 'run', 'weight' => 1, 'once' => false, 'retired' => false]);
        $node = EncounterNode::create(['encounter_id' => $encounter->id, 'node_key' => 'n', 'body' => 'x', 'entry' => true, 'ends' => true]);

        return EncounterOutcome::create(['node_id' => $node->id, 'branch' => OutcomeBranch::Always, 'position' => 0, 'kind' => $kind, 'amount' => $amount, 'ref' => $ref]);
    }

    private function liveRun(): Run
    {
        System::factory()->at(8, 8)->grade(2)->withIce(Ice::query()->where('level', 1)->sole())->create(['address' => 'DEADBEEF']);
        $result = app(RunStarter::class)->start($this->character, RunPurpose::Extraction);
        $this->assertTrue($result->succeeded());
        $this->character = $this->character->fresh();

        return $result->run->fresh();
    }

    public function test_trace_moves_and_never_lands(): void
    {
        $run = $this->liveRun();
        $run->forceFill(['trace' => 40])->save();

        app(EncounterOutcomes::class)->apply($this->character, $this->outcome(OutcomeKind::Trace, 30));
        $this->assertSame(70, (int) $run->fresh()->trace);

        app(EncounterOutcomes::class)->apply($this->character, $this->outcome(OutcomeKind::Trace, 500));
        $this->assertSame(99, (int) $run->fresh()->trace, 'the simulator lands a trace, an encounter only brings it close');

        app(EncounterOutcomes::class)->apply($this->character, $this->outcome(OutcomeKind::Trace, -1000));
        $this->assertSame(0, (int) $run->fresh()->trace);
    }

    public function test_wake_ice_and_detected_notice_the_run(): void
    {
        $run = $this->liveRun();
        $run->forceFill(['detected' => false, 'ice_id' => null])->save();

        app(EncounterOutcomes::class)->apply($this->character, $this->outcome(OutcomeKind::WakeIce));

        $this->assertTrue((bool) $run->fresh()->detected);
        $this->assertNotNull($run->fresh()->ice_id);
    }

    public function test_run_outcomes_do_nothing_outside_a_run(): void
    {
        $line = app(EncounterOutcomes::class)->apply($this->character, $this->outcome(OutcomeKind::Detected));

        $this->assertNull($line);
    }

    public function test_grid_move_puts_somebody_somewhere_and_refuses_off_the_grid(): void
    {
        app(EncounterOutcomes::class)->apply($this->character, $this->outcome(OutcomeKind::GridMove, ref: '3,4'));
        $fresh = $this->character->fresh();
        $this->assertSame([3, 4], [$fresh->grid_x, $fresh->grid_y]);

        app(EncounterOutcomes::class)->apply($fresh, $this->outcome(OutcomeKind::GridMove, ref: '99,99'));
        $this->assertSame([3, 4], [$this->character->fresh()->grid_x, $this->character->fresh()->grid_y]);
    }
}
