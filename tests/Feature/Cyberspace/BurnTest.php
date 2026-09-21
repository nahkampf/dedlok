<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Checks\CheckRoller;
use App\Cyberspace\BackdoorKind;
use App\Cyberspace\Backdoors;
use App\Cyberspace\RunAction;
use App\Cyberspace\RunEnding;
use App\Cyberspace\RunPurpose;
use App\Cyberspace\RunStarter;
use App\Cyberspace\Simulator;
use App\Models\Backdoor;
use App\Models\Character;
use App\Models\Ice;
use App\Models\Run;
use App\Models\System;
use App\Models\SystemContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/**
 * Burning a machine out of existence.
 *
 * The last of the three run purposes and the last of lockdown's four triggers,
 * and it is the trigger that resolves itself: a burned machine cannot shut,
 * because there is nothing left to shut.
 *
 * What is asserted here is mostly absence — no machine, no address, no
 * backdoors, no loot, no raid, no lockdown — which is the whole of what a burn
 * leaves behind. CYBERSPACE.md: *dead, then empty, then eventually something
 * new*.
 */
final class BurnTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 16);

        $this->character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['archetype' => 3],
        ));

        $this->equipReadyDeck($this->character);

        $this->character->forceFill([
            'grid_x' => 8,
            'grid_y' => 8,
            'egress_trace_at' => 0,
            'egress_si_at' => 0,
        ])->save();
        $this->character->refresh();
    }

    // ---- what it leaves behind ---------------------------------------------

    public function test_a_burn_takes_the_machine_out_of_existence(): void
    {
        $system = $this->machine();
        $run = $this->begin(RunPurpose::Burn);

        $this->simulator()->take($this->character, $run, RunAction::Burn);

        $this->assertNull(System::query()->find($system->id), 'the machine is gone');
        $this->assertSame(0, System::query()->where('address', 'DEADBEEF')->count());
    }

    /**
     * THE POSITION IS SIMPLY EMPTY. Not relocated and not reserved — a burn is
     * the one thing that consumes the grid's empty reserve, and what refills
     * the square is the next seeding pass rather than anything here.
     */
    public function test_the_position_it_held_is_empty(): void
    {
        $this->machine();
        $run = $this->begin(RunPurpose::Burn);

        $this->simulator()->take($this->character, $run, RunAction::Burn);

        $this->assertSame(0, System::query()->where('x', 8)->where('y', 8)->count());
    }

    /** The run is over, and over as a success: they did what they came to do. */
    public function test_the_run_ends_completed(): void
    {
        $this->machine();
        $run = $this->begin(RunPurpose::Burn);

        $result = $this->simulator()->take($this->character, $run, RunAction::Burn);

        $this->assertSame(RunEnding::Completed, $result->ending);
        $this->assertSame(RunEnding::Completed->value, $run->fresh()->ending);
        $this->assertNull($this->character->fresh()->current_run_id);
    }

    /**
     * Everything keyed by the MACHINE goes; everything keyed by the ADDRESS
     * stays and stops describing anything. That split is the whole reason
     * `backdoors.system_id` is a foreign key while a scan, a sighting and a
     * run all carry an address instead.
     */
    public function test_every_backdoor_into_it_goes_with_it(): void
    {
        $system = $this->machine();
        (new Backdoors)->install($this->character, $system, BackdoorKind::Siphon);

        $this->assertSame(1, Backdoor::query()->count());

        $run = $this->begin(RunPurpose::Burn);
        $this->simulator()->take($this->character, $run, RunAction::Burn);

        $this->assertSame(0, Backdoor::query()->count(), 'the cascade took it');
    }

    /** Its contents are not loot. They were in the machine. */
    public function test_nothing_comes_out_of_a_burned_machine(): void
    {
        $system = $this->machine();
        SystemContent::query()->create([
            'system_id' => $system->id,
            'kind' => 'creds',
            'label' => 'Float',
            'amount' => 5000,
        ]);

        $creds = (int) $this->character->creds;
        $run = $this->begin(RunPurpose::Burn);

        $this->simulator()->take($this->character, $run, RunAction::Burn);

        $this->assertSame($creds, (int) $this->character->fresh()->creds, 'no haul');
        $this->assertFalse((bool) $run->fresh()->extracted, 'no raid to count');
        $this->assertSame(0, SystemContent::query()->count(), 'gone with the machine');
    }

    /**
     * A BURNED MACHINE DOES NOT LOCK DOWN, and not because anything says so:
     * `Lockdown::afterRun()` looks the system up by address as the run ends,
     * finds nothing, and returns false. The trigger makes its own subject
     * absent. Asserted with a run that was loudly noticed, which is the case
     * that would otherwise shut it.
     */
    public function test_a_noticed_burn_leaves_no_lockdown_because_there_is_nothing_to_shut(): void
    {
        $this->machine(iceLevel: 6);
        $run = $this->begin(RunPurpose::Burn, noticed: true);

        $this->assertTrue((bool) $run->fresh()->detected, 'it saw them');

        $this->simulator()->take($this->character, $run, RunAction::Burn);

        $this->assertSame(0, System::query()->count(), 'no machine, shut or otherwise');
    }

    // ---- what it costs -----------------------------------------------------

    public function test_burning_puts_heat_on_the_character(): void
    {
        Config::set('cyberspace.burn.heat', 3);

        $this->machine();
        $heat = (int) $this->character->heat;
        $run = $this->begin(RunPurpose::Burn);

        $this->simulator()->take($this->character, $run, RunAction::Burn);

        $this->assertSame($heat + 3, (int) $this->character->fresh()->heat);
    }

    // ---- and who else was in there -----------------------------------------

    /**
     * SOMEBODY ELSE'S RUN ENDS WITH IT, which is the price of a shared world —
     * "a machine you are standing in is a machine somebody else can burn". One
     * flat point and nothing else: the cause was not theirs.
     */
    public function test_anybody_else_inside_is_thrown_out(): void
    {
        $this->machine();

        $other = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['archetype' => 3],
        ));
        $this->equipReadyDeck($other);
        $other->forceFill(['grid_x' => 8, 'grid_y' => 8])->save();
        $other->refresh();

        $theirs = app(RunStarter::class)->start($other->fresh(), RunPurpose::Extraction)->run;
        $this->assertNotNull($theirs);

        $run = $this->begin(RunPurpose::Burn);
        $this->simulator()->take($this->character, $run, RunAction::Burn);

        $this->assertSame(RunEnding::Cancelled->value, $theirs->fresh()->ending);
        $this->assertNull($other->fresh()->current_run_id);
        $this->assertFalse($other->fresh()->isJackedIn(), 'and out of cyberspace');

        // The system row being gone is what makes their run's address stop
        // resolving — an ordinary answer, never an error.
        $this->assertNull($theirs->fresh()->system());
    }

    /** The burner is not cancelled out from under their own burn. */
    public function test_the_burner_is_not_thrown_out_of_their_own_run(): void
    {
        $this->machine();
        $run = $this->begin(RunPurpose::Burn);

        $this->simulator()->take($this->character, $run, RunAction::Burn);

        $this->assertSame(RunEnding::Completed->value, $run->fresh()->ending);
    }

    // ---- and when it does not land -----------------------------------------

    /**
     * ONE LINE FOR EVERY REASON, and the wrong purpose is one of them. The turn
     * is spent and the machine is still standing — a burn on an extraction run
     * wastes the turn the same silent way a cut with no icebreaker does.
     */
    public function test_burning_on_another_purpose_wastes_the_turn(): void
    {
        $this->machine();
        $run = $this->begin(RunPurpose::Extraction);

        $result = $this->simulator()->take($this->character, $run, RunAction::Burn);

        $this->assertSame(1, System::query()->count(), 'still standing');
        $this->assertNull($result->ending, 'the run carries on');
        $this->assertSame(1, (int) $run->fresh()->turn, 'and the turn was spent');
    }

    /** A core that will not open stops it, and says nothing. */
    public function test_a_core_that_will_not_open_stops_a_burn(): void
    {
        $this->machine();
        $run = $this->begin(RunPurpose::Burn);
        $run->forceFill(['os_locked' => true])->save();

        // Every check fails, which is the only way to be sure WHICH way the
        // core roll went: it is a roll now rather than a verdict, so a test
        // about the gate has to pin it like any other.
        $this->instance(CheckRoller::class, CheckRoller::fromConfig(static fn (): float => 1.0));

        $result = $this->simulator()->take($this->character, $run->fresh(), RunAction::Burn);

        $this->assertSame(1, System::query()->count(), 'still standing');
        $this->assertNull($result->ending);
        $this->assertTrue($run->fresh()->os_locked, 'and it is still shut');

        foreach ($result->log as $line) {
            $this->assertStringNotContainsStringIgnoringCase('operating system', $line['text']);
        }
    }

    /** A machine that went out from under the run is the same one line. */
    public function test_a_machine_that_has_already_gone_is_the_same_failure(): void
    {
        $system = $this->machine();
        $run = $this->begin(RunPurpose::Burn);

        $system->delete();

        $result = $this->simulator()->take($this->character, $run->fresh(), RunAction::Burn);

        $this->assertNull($result->ending, 'the run carries on and they leave under their own power');
    }

    // ---- over HTTP ---------------------------------------------------------

    public function test_the_screen_offers_burn_only_on_a_burn_run(): void
    {
        $this->machine();
        $this->begin(RunPurpose::Extraction);

        $this->actingAs($this->character->fresh()->user)
            ->get(route('run.show'))
            ->assertOk()
            ->assertDontSee('value="burn"', false);
    }

    public function test_a_burn_can_be_taken_over_http(): void
    {
        $this->machine();
        $this->begin(RunPurpose::Burn);

        $this->actingAs($this->character->fresh()->user)
            ->get(route('run.show'))
            ->assertOk()
            ->assertSee('value="burn"', false);

        $this->actingAs($this->character->fresh()->user)
            ->post(route('run.act'), ['action' => RunAction::Burn->value])
            ->assertRedirect();

        $this->assertSame(0, System::query()->count());
        $this->assertNull($this->character->fresh()->current_run_id);
    }

    // ---- fixtures ----------------------------------------------------------

    private function machine(?int $iceLevel = null): System
    {
        $factory = System::factory()->at(8, 8)->grade(1)->withTracer(2, 4);

        if ($iceLevel !== null) {
            $factory = $factory->withIce(Ice::query()->where('level', $iceLevel)->sole());
        }

        return $factory->create(['address' => 'DEADBEEF']);
    }

    private function begin(RunPurpose $purpose, bool $noticed = false): Run
    {
        $this->instance(
            CheckRoller::class,
            CheckRoller::fromConfig(static fn (): float => $noticed ? 1.0 : 0.0),
        );

        $run = app(RunStarter::class)->start($this->character->fresh(), $purpose)->run;

        $this->character->refresh();

        return $run;
    }

    private function simulator(): Simulator
    {
        return app(Simulator::class);
    }
}
