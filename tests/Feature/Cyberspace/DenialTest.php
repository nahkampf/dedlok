<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Checks\CheckRoller;
use App\Cyberspace\Party\PartyTurns;
use App\Cyberspace\RunAction;
use App\Cyberspace\RunEnding;
use App\Cyberspace\RunPurpose;
use App\Cyberspace\RunRefusal;
use App\Cyberspace\RunStarter;
use App\Cyberspace\Simulator;
use App\Cyberspace\SystemType;
use App\Missions\MissionAction;
use App\Models\Character;
use App\Models\Ice;
use App\Models\Run;
use App\Models\RunParty;
use App\Models\System;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/**
 * Denial runs (CYBERSPACE.md, *Denial*): flood a node with bandwidth until it locks down.
 */
final class DenialTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    private Character $character;

    private System $node;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 16);
        Config::set('cyberspace.denial', ['target' => [1 => 100, 2 => 100, 3 => 100], 'per_bandwidth' => 1.0, 'turns' => 12, 'drain' => [1 => [5, 5], 2 => [5, 5], 3 => [5, 5]]]);
        $this->instance(CheckRoller::class, CheckRoller::fromConfig(static fn (): float => 0.0));

        $this->node = System::factory()->at(8, 8)->grade(1)->withIce(Ice::query()->where('level', 6)->sole())->create(['address' => 'DEADBEEF']);
        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        $this->equipReadyDeck($this->character);
        $this->character->forceFill(['grid_x' => 8, 'grid_y' => 8])->save();
    }

    private function start(): Run
    {
        return app(RunStarter::class)->start($this->character->fresh(), RunPurpose::Denial)->run;
    }

    private function simulator(): Simulator
    {
        // 101 on a d100: the ICE always misses, so the test is about the meter.
        return Simulator::fromConfig(static fn (int $min, int $max): int => min($max, 101));
    }

    public function test_a_flood_builds_pressure_the_first_one_wakes_the_ice_and_the_node_pushes_back(): void
    {
        $run = $this->start();
        $this->assertFalse($run->detected);

        $this->simulator()->take($this->character->fresh(), $run, RunAction::Flood);

        $run->refresh();
        $this->assertTrue($run->detected);
        $this->assertNotNull($run->ice_id);
        // 50 bandwidth x 1.0 = 50, less the 5 the node pushes back that turn.
        $this->assertSame(45.0, $run->pressure);
    }

    public function test_a_full_meter_locks_the_node_down_and_ticks_the_mission(): void
    {
        $this->assertTrue(in_array(MissionAction::Denial->value, MissionAction::values(), true));
        $run = $this->start();
        $result = null;

        for ($i = 0; $i < 3; $i++) {
            $result = $this->simulator()->take($this->character->fresh(), $run->fresh(), RunAction::Flood);
        }

        $this->assertSame(RunEnding::Completed, $result->ending);
        $this->assertTrue($this->node->fresh()->isLockedDown());
        $this->assertNull($this->character->fresh()->current_run_id);
    }

    public function test_only_a_denial_run_floods(): void
    {
        $run = app(RunStarter::class)->start($this->character->fresh(), RunPurpose::Extraction)->run;

        $this->simulator()->take($this->character->fresh(), $run, RunAction::Flood);

        $this->assertSame(0.0, $run->fresh()->pressure);
    }

    public function test_it_is_never_against_a_gateway(): void
    {
        $gate = System::factory()->at(3, 3)->create(['type' => SystemType::Gateway, 'address' => 'AABBCCDD']);
        $this->character->forceFill(['grid_x' => 3, 'grid_y' => 3])->save();

        $this->assertSame(RunRefusal::WrongPurpose, app(RunStarter::class)->start($this->character->fresh(), RunPurpose::Denial)->refusal);
        $this->assertNotNull($gate->id);
    }

    /** @return array{0: Character, 1: Run} */
    private function partyMember(RunParty $party, int $x): array
    {
        $character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        $this->equipReadyDeck($character);
        $character->forceFill(['grid_x' => 8, 'grid_y' => 8])->save();
        $run = Run::query()->create([
            'character_id' => $character->id, 'run_party_id' => $party->id, 'address' => 'DEADBEEF', 'x' => 8, 'y' => 8,
            'purpose' => RunPurpose::Denial->value, 'turn' => 0, 'started_at' => now(),
        ]);
        $character->forceFill(['current_run_id' => $run->id])->save();

        return [$character, $run];
    }

    public function test_a_party_floods_together_and_the_node_pushes_back_once_a_round(): void
    {
        Config::set('cyberspace.denial.target', [1 => 200]);
        $owner = Character::factory()->create();
        $party = RunParty::query()->create(['owner_character_id' => $owner->id, 'address' => 'DEADBEEF', 'x' => 8, 'y' => 8]);
        [, $a] = $this->partyMember($party, 8);
        [, $b] = $this->partyMember($party, 8);
        $turns = new PartyTurns($this->simulator());

        $turns->submit($a, RunAction::Flood);
        $this->assertSame(0.0, $a->fresh()->pressure, 'the round waits for the other runner');

        $turns->submit($b, RunAction::Flood);

        // 50 + 50 from two decks, less one push-back of 5.
        $this->assertSame(95.0, $a->fresh()->pressure);
        $this->assertSame(95.0, $b->fresh()->pressure);
    }

    public function test_a_full_party_meter_ends_every_run_together_without_cancelling_anyone(): void
    {
        $owner = Character::factory()->create();
        $party = RunParty::query()->create(['owner_character_id' => $owner->id, 'address' => 'DEADBEEF', 'x' => 8, 'y' => 8]);
        [$ca, $a] = $this->partyMember($party, 8);
        [$cb, $b] = $this->partyMember($party, 8);
        $before = [$ca->current_synaptic_integrity, $cb->current_synaptic_integrity];
        $turns = new PartyTurns($this->simulator());

        $turns->submit($a, RunAction::Flood);
        $turns->submit($b, RunAction::Flood);

        $this->assertSame('completed', $a->fresh()->getRawOriginal('ending'));
        $this->assertSame('completed', $b->fresh()->getRawOriginal('ending'));
        $this->assertTrue($this->node->fresh()->isLockedDown());
        $this->assertEquals($before, [$ca->fresh()->current_synaptic_integrity, $cb->fresh()->current_synaptic_integrity]);
    }

    public function test_the_node_shuts_when_the_last_runner_is_out_and_nobody_is_cancelled_before(): void
    {
        Config::set('cyberspace.denial.target', [1 => 1000]);
        $owner = Character::factory()->create();
        $party = RunParty::query()->create(['owner_character_id' => $owner->id, 'address' => 'DEADBEEF', 'x' => 8, 'y' => 8]);
        [$ca, $a] = $this->partyMember($party, 8);
        [$cb, $b] = $this->partyMember($party, 8);
        Run::query()->whereKey([$a->id, $b->id])->update(['detected' => true]);
        $before = $cb->current_synaptic_integrity;
        $turns = new PartyTurns($this->simulator());

        $turns->submit($a, RunAction::Egress);
        $turns->submit($b, RunAction::Hold);

        $this->assertNotNull($a->fresh()->ended_at);
        $this->assertNull($b->fresh()->ended_at, 'the others carry on');
        $this->assertFalse($this->node->fresh()->isLockedDown());
        $this->assertEquals($before, $cb->fresh()->current_synaptic_integrity);

        $this->simulator()->take($cb->fresh(), $b->fresh(), RunAction::Egress);

        $this->assertNotNull($b->fresh()->ended_at);
        $this->assertTrue($this->node->fresh()->isLockedDown());
    }

    public function test_a_landed_backdoor_ends_the_run_for_the_whole_party(): void
    {
        Config::set('cyberspace.denial.target', [1 => 1000]);
        $owner = Character::factory()->create();
        $party = RunParty::query()->create(['owner_character_id' => $owner->id, 'address' => 'DEADBEEF', 'x' => 8, 'y' => 8]);
        [, $a] = $this->partyMember($party, 8);
        [, $b] = $this->partyMember($party, 8);
        Run::query()->whereKey($a->id)->update(['backdoored' => true]);
        $turns = new PartyTurns($this->simulator());

        $turns->submit($a, RunAction::Hold);
        $turns->submit($b, RunAction::Hold);

        $this->assertSame('completed', $a->fresh()->getRawOriginal('ending'));
        $this->assertSame('completed', $b->fresh()->getRawOriginal('ending'));
        $this->assertFalse($this->node->fresh()->isLockedDown());
    }

    public function test_a_party_reads_one_log_that_names_who_did_what(): void
    {
        Config::set('cyberspace.denial.target', [1 => 1000]);
        $owner = Character::factory()->create();
        $party = RunParty::query()->create(['owner_character_id' => $owner->id, 'address' => 'DEADBEEF', 'x' => 8, 'y' => 8]);
        [$ca, $a] = $this->partyMember($party, 8);
        [$cb, $b] = $this->partyMember($party, 8);
        $turns = new PartyTurns($this->simulator());

        $turns->submit($a, RunAction::Hold);
        $turns->submit($b, RunAction::Hold);

        $this->assertSame(
            collect($a->fresh()->log)->pluck('by')->filter()->sort()->values()->all(),
            collect($b->fresh()->log)->pluck('by')->filter()->sort()->values()->all(),
        );
        $this->assertEqualsCanonicalizing([$ca->handle, $cb->handle], collect($a->fresh()->log)->pluck('by')->filter()->unique()->values()->all());
    }

    public function test_the_pushback_is_a_roll_in_the_grades_range(): void
    {
        Config::set('cyberspace.denial.drain', [1 => [0, 68]]);
        $run = $this->start();

        foreach ([[0, 0.0], [34, 34.0], [68, 68.0]] as [$die, $expected]) {
            $simulator = Simulator::fromConfig(static fn (int $min, int $max): int => $die);
            $this->assertSame($expected, $simulator->rollDenialDrain($run));
        }
    }

    public function test_a_solo_flooder_is_shaken_off_after_the_turn_limit(): void
    {
        Config::set('cyberspace.denial', ['target' => [1 => 1000], 'per_bandwidth' => 1.0, 'turns' => 2, 'drain' => [1 => [5, 5]]]);
        $run = $this->start();

        $this->assertNull($this->simulator()->take($this->character->fresh(), $run, RunAction::Flood)->ending);
        $result = $this->simulator()->take($this->character->fresh(), $run->fresh(), RunAction::Flood);

        $this->assertSame(RunEnding::Egress, $result->ending);
        $this->assertNull($this->character->fresh()->current_run_id);
    }
}
