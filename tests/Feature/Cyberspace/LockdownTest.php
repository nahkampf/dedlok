<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Characters\WaitingRoom;
use App\Checks\CheckRoller;
use App\Cyberspace\Lockdown;
use App\Cyberspace\MapCell;
use App\Cyberspace\MapView;
use App\Cyberspace\Position;
use App\Cyberspace\RunAction;
use App\Cyberspace\RunEnding;
use App\Cyberspace\RunPurpose;
use App\Cyberspace\RunRefusal;
use App\Cyberspace\RunStarter;
use App\Cyberspace\Simulator;
use App\Cyberspace\Sweep;
use App\Models\Character;
use App\Models\Ice;
use App\Models\Run;
use App\Models\System;
use App\Stats\Resolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/**
 * A machine that knows it was violated shuts, and when it opens it is
 * somewhere else.
 *
 * Every roll is pinned, including the one that picks the window's length and
 * the one that picks where a machine moves to.
 */
final class LockdownTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 16);
        Config::set('cyberspace.raids.limit', 3);

        $this->character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['archetype' => 3],
        ));

        $this->equipReadyDeck($this->character);
        $this->character->forceFill([
            'grid_x' => 8, 'grid_y' => 8, 'egress_trace_at' => 0, 'egress_si_at' => 0,
        ])->save();
        $this->character->refresh();
    }

    // ---- what makes a machine find out -------------------------------------

    /**
     * A CLEAN EXTRACTION LEAVES NO LOCKDOWN BEHIND. In and out unseen leaves a
     * machine that does not know anything happened — which is what makes an
     * unnoticed run worth having and what gives a quiet thief somewhere to
     * come back to.
     */
    public function test_an_unnoticed_run_leaves_it_none_the_wiser(): void
    {
        $system = $this->machine();
        $run = $this->begin(noticed: false);

        $this->assertFalse($run->detected);

        $this->leave();

        $this->assertFalse($system->fresh()->isLockedDown());
    }

    /** Being noticed is the whole trigger, whatever else the run did. */
    public function test_a_noticed_run_shuts_it(): void
    {
        $system = $this->machine();
        $this->begin(noticed: true);

        $this->leave();

        $this->assertTrue($system->fresh()->isLockedDown());
    }

    /**
     * THE ICE ACTIVATING IS NOT A SECOND QUESTION. Detection is what wakes it,
     * so a run that fought something was by definition noticed — the table's
     * two rows are one fact here.
     */
    public function test_a_fight_is_a_noticed_run_by_definition(): void
    {
        $system = $this->machine(iceLevel: 3);
        $run = $this->begin(noticed: true);

        $this->assertNotNull($run->ice_id, 'it woke up');
        $this->assertTrue($run->detected);
    }

    /** Three raids and it shuts anyway, however quiet the third one was. */
    public function test_the_spent_counter_shuts_it_without_being_noticed(): void
    {
        $system = $this->machine();
        $system->forceFill(['raids' => 3])->save();

        $run = $this->begin(noticed: false);
        $this->assertFalse($run->detected);

        $this->leave();

        $this->assertTrue($system->fresh()->isLockedDown());
    }

    /** The window is not extended by a second run: it is not a sentence. */
    public function test_a_machine_already_shut_is_left_alone(): void
    {
        $system = $this->machine();
        $system->forceFill(['locked_down_until' => now()->addHours(5)])->save();
        $was = $system->fresh()->locked_down_until;

        $run = Run::query()->create([
            'character_id' => $this->character->id,
            'address' => 'DEADBEEF',
            'x' => 8, 'y' => 8,
            'purpose' => RunPurpose::Extraction->value,
            'turn' => 1,
            'detected' => true,
            'started_at' => now(),
        ]);

        $this->assertFalse($this->lockdown()->afterRun($run));
        $this->assertEquals($was, $system->fresh()->locked_down_until);
    }

    // ---- what the window does ----------------------------------------------

    public function test_the_window_is_between_two_and_six_hours(): void
    {
        $system = $this->machine();

        Lockdown::fromConfig(static fn (int $min, int $max): int => $min)->shut($system);
        $this->assertEqualsWithDelta(2, now()->diffInHours($system->fresh()->locked_down_until, false), 0.02);

        $system->forceFill(['locked_down_until' => null])->save();

        Lockdown::fromConfig(static fn (int $min, int $max): int => $max)->shut($system->fresh());
        $this->assertEqualsWithDelta(6, now()->diffInHours($system->fresh()->locked_down_until, false), 0.02);
    }

    /** There is no way in while it is shut. */
    public function test_a_shut_machine_refuses_a_run(): void
    {
        $system = $this->machine();
        $this->lockdown()->shut($system);

        $result = app(RunStarter::class)->start($this->character->fresh(), RunPurpose::Extraction);

        $this->assertFalse($result->succeeded());
        $this->assertSame(RunRefusal::LockedDown, $result->refusal);
    }

    /** A window that has closed is the same answer as never having had one. */
    public function test_a_window_in_the_past_is_not_a_lockdown(): void
    {
        $system = $this->machine();
        $system->forceFill(['locked_down_until' => now()->subMinute()])->save();

        $this->assertFalse($system->fresh()->isLockedDown());
    }

    // ---- anybody still inside ----------------------------------------------

    /**
     * THE ONE CASE WHERE A LOCKDOWN REACHES A LIVE RUN, and `RunEnding::Cancelled`
     * finally has a caller. A flat point of Synaptic Integrity and nothing
     * else: no defeat effect, no raid counted, no trace, because the cause was
     * not theirs.
     */
    public function test_it_throws_out_somebody_who_was_still_inside(): void
    {
        $system = $this->machine();
        $stranger = $this->stranger();

        $run = Run::query()->create([
            'character_id' => $stranger->id,
            'address' => 'DEADBEEF',
            'x' => 8, 'y' => 8,
            'purpose' => RunPurpose::Extraction->value,
            'turn' => 4,
            'started_at' => now(),
        ]);
        $stranger->forceFill(['current_run_id' => $run->id])->save();

        $before = $stranger->fresh()->current_synaptic_integrity;

        $this->lockdown()->shut($system);

        $fresh = $stranger->fresh();

        $this->assertFalse($run->fresh()->isLive());
        $this->assertSame(RunEnding::Cancelled->value, $run->fresh()->ending);
        $this->assertNull($fresh->current_run_id, 'the lock is off');
        $this->assertFalse($fresh->isJackedIn(), 'and they are out of cyberspace');
        $this->assertSame($before - 1, $fresh->current_synaptic_integrity, 'one flat point');
    }

    /** One point can put somebody on the floor, and that is not unfair. */
    public function test_a_cancellation_can_put_somebody_down(): void
    {
        $system = $this->machine();
        $stranger = $this->stranger();
        $stranger->forceFill(['current_synaptic_integrity' => 1])->save();

        $run = Run::query()->create([
            'character_id' => $stranger->id,
            'address' => 'DEADBEEF',
            'x' => 8, 'y' => 8,
            'purpose' => RunPurpose::Extraction->value,
            'turn' => 1,
            'started_at' => now(),
        ]);
        $stranger->forceFill(['current_run_id' => $run->id])->save();

        $this->lockdown()->shut($system);

        $fresh = $stranger->fresh();

        $this->assertSame(0, $fresh->current_synaptic_integrity);
        $this->assertTrue(app(WaitingRoom::class)->isDown($fresh));
        $this->assertSame('cancelled', $fresh->waiting_cause['reason']);
    }

    /** It never reaches the person it was a consequence of: they are already out. */
    public function test_the_runner_who_caused_it_is_not_cancelled(): void
    {
        $this->machine();
        $run = $this->begin(noticed: true);

        $this->leave();

        $this->assertSame(RunEnding::Egress->value, $run->fresh()->ending, 'they left under their own power');
    }

    // ---- and then it moves --------------------------------------------------

    public function test_the_sweep_opens_and_moves_what_is_due(): void
    {
        $system = $this->machine();
        $system->forceFill(['locked_down_until' => now()->subMinute(), 'raids' => 3])->save();

        $this->assertSame(1, $this->lockdown()->settle());

        $fresh = $system->fresh();

        $this->assertNull($fresh->locked_down_until, 'the window is gone');
        $this->assertNotSame('DEADBEEF', $fresh->address, 'and so is the address');
        $this->assertSame(0, $fresh->raids, 'books balanced');
    }

    public function test_the_sweep_leaves_a_window_that_is_still_open(): void
    {
        $system = $this->machine();
        $system->forceFill(['locked_down_until' => now()->addHours(3)])->save();

        $this->assertSame(0, $this->lockdown()->settle());
        $this->assertSame('DEADBEEF', $system->fresh()->address);
    }

    public function test_the_command_reports_what_it_moved(): void
    {
        $system = $this->machine();
        $system->forceFill(['locked_down_until' => now()->subMinute()])->save();

        $this->artisan('cyberspace:lockdowns --dry-run')
            ->expectsOutputToContain('1 machine(s) are due')
            ->assertSuccessful();

        $this->assertSame('DEADBEEF', $system->fresh()->address, 'a dry run wrote nothing');

        $this->artisan('cyberspace:lockdowns')->assertSuccessful();

        $this->assertNotSame('DEADBEEF', $system->fresh()->address);
    }

    // ---- what the map remembers ---------------------------------------------

    /**
     * THE MAP DRAWS WHAT YOU SAW. A sighting records the window, so a machine
     * that shut after you walked past still looks fine until you come back —
     * the same rule its address and its owner already follow.
     */
    public function test_a_sighting_remembers_a_machine_that_was_shut(): void
    {
        $system = $this->machine();
        $this->lockdown()->shut($system);

        $cell = $this->sightOfTheMachine();

        $this->assertTrue($cell->isRebooting);
        // `classes()` is what the template paints with; `modifier()` is only
        // the cell's own kind, and rebooting is orthogonal to that — a
        // rebooting compute node is still a compute node.
        $this->assertStringContainsString('cell--rebooting', $cell->classes());
    }

    public function test_a_sighting_of_an_ordinary_machine_is_not_rebooting(): void
    {
        $this->machine();

        $this->assertFalse($this->sightOfTheMachine()->isRebooting);
    }

    // ---- fixtures ------------------------------------------------------------

    private function machine(?int $iceLevel = null): System
    {
        $factory = System::factory()->at(8, 8)->grade(1)->withTracer(2, 4);

        if ($iceLevel !== null) {
            $factory = $factory->withIce(Ice::query()->where('level', $iceLevel)->sole());
        }

        return $factory->create(['address' => 'DEADBEEF']);
    }

    /** Somebody else's character, standing in the same machine. */
    private function stranger(): Character
    {
        $stranger = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['archetype' => 3],
        ));

        $this->equipReadyDeck($stranger);
        $stranger->forceFill(['grid_x' => 8, 'grid_y' => 8])->save();

        return $stranger->fresh();
    }

    /**
     * Look at the machine from next door, and hand back its cell.
     *
     * NEXT DOOR AND NOT ON IT, for two reasons: jacking in lands at the portal
     * rather than wherever the test put a machine, and a cell you are standing
     * on wears `cell--you`, which outranks what is under it. Seeing it from one
     * square away is how a player sees anything anyway.
     */
    private function sightOfTheMachine(): MapCell
    {
        $this->character->forceFill(['grid_x' => 7, 'grid_y' => 8])->save();
        $this->character->refresh();

        Sweep::fromConfig()->from($this->character, new Position(7, 8));

        $map = MapView::for($this->character->fresh());

        return $map->rows[$map->range][$map->range + 1];
    }

    /** A die pinned to the top of every range, so a move goes somewhere known. */
    private function lockdown(): Lockdown
    {
        return Lockdown::fromConfig(static fn (int $min, int $max): int => $max);
    }

    private function begin(bool $noticed): Run
    {
        $this->instance(
            CheckRoller::class,
            CheckRoller::fromConfig(static fn (): float => $noticed ? 1.0 : 0.0),
        );

        return app(RunStarter::class)->start($this->character->fresh(), RunPurpose::Extraction)->run;
    }

    /** Leave under their own power, with the egress roll pinned to pass. */
    private function leave(): void
    {
        $this->instance(CheckRoller::class, CheckRoller::fromConfig(static fn (): float => 0.0));

        Simulator::fromConfig(
            static fn (int $min, int $max): int => $max,
            app(Resolver::class),
            app(CheckRoller::class),
        )->take(
            $this->character->fresh(),
            Run::query()->live()->where('character_id', $this->character->id)->sole(),
            RunAction::Egress,
        );
    }
}
