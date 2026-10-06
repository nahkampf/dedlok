<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Checks\CheckRoller;
use App\Cyberspace\RunAction;
use App\Cyberspace\RunPurpose;
use App\Cyberspace\RunStarter;
use App\Cyberspace\Simulator;
use App\Models\Character;
use App\Models\Ice;
use App\Models\Run;
use App\Models\System;
use App\Models\SystemContent;
use App\Stats\Resolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/**
 * Every run route, over HTTP, in the states a run is actually in.
 *
 * ## Why this exists
 *
 * Two bugs shipped in 0.10.0 and neither was visible to fourteen hundred
 * passing tests, because every one of them called `Simulator` directly and
 * nothing ever posted to `run.act`. `Simulator` was not bound in the container
 * and its constructor takes an injected die, so the controller that asked for
 * it could not be built: **every turn of every run was a 500**, and the tests
 * that exercised turns were all holding an instance they had made themselves.
 *
 * So this is deliberately not a test of the simulator. It asserts the far
 * duller thing the unit tests structurally cannot: that the routes resolve,
 * the views render, and nothing 500s. Where a unit test builds its own
 * collaborators, this one takes what the container gives a real request.
 *
 * ## It walks the actions rather than naming them
 *
 * `RunAction::values()` is the list, so a new action is covered the day it is
 * added rather than the day somebody remembers. A turn is spent per case, and
 * what a turn DID is other tests' business — the assertion here is only that
 * the request came back something other than a server error.
 */
final class RunScreenSmokeTest extends TestCase
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

        // Files here run to thousands of QB; storage is not what these test.
        config(['decks.onboard_storage' => 1_000_000]);
        $this->equipReadyDeck($this->character);

        $this->character->forceFill([
            'grid_x' => 8,
            'grid_y' => 8,
            // Both marks off: a threshold firing would end the run before the
            // action under test got to be the thing that resolved it.
            'egress_trace_at' => 0,
            'egress_si_at' => 0,
        ])->save();
        $this->character->refresh();
    }

    // ---- the screen, in the states a run reaches ---------------------------

    public function test_the_screen_renders_on_a_quiet_machine(): void
    {
        $this->machine();
        $this->begin();

        $this->get(route('run.show'))->assertOk();
    }

    public function test_the_screen_renders_mid_fight(): void
    {
        $this->machine(iceLevel: 8);
        $this->begin(noticed: true);

        $this->get(route('run.show'))->assertOk();
    }

    /**
     * A MACHINE THAT WENT. The run carries an address rather than a foreign
     * key precisely so this is a state and not an integrity error — and the
     * screen has to draw it.
     */
    public function test_the_screen_renders_when_the_machine_has_gone(): void
    {
        $system = $this->machine();
        $this->begin();

        $system->delete();

        $this->get(route('run.show'))->assertOk();
    }

    public function test_the_screen_renders_with_a_transfer_running(): void
    {
        $system = $this->machine();
        SystemContent::query()->create([
            'system_id' => $system->id,
            'kind' => 'data',
            'label' => 'Ledger',
            'size_qb' => 4000,
            'value_sealed' => 10,
            'value_broken' => 90,
        ]);

        $run = $this->begin();
        $this->post(route('run.act'), ['action' => RunAction::Extract->value]);

        $this->assertNotNull($run->fresh()->extracting_qb, 'a pull is under way');
        $this->get(route('run.show'))->assertOk();
    }

    public function test_the_log_shows_the_newest_line_first(): void
    {
        $this->machine();
        $run = $this->begin();
        $run->forceFill(['log' => [
            ['text' => 'FIRST LINE OF THE LOG', 'tone' => 'neutral', 'turn' => 1],
            ['text' => 'SECOND LINE OF THE LOG', 'tone' => 'neutral', 'turn' => 2],
            ['text' => 'THIRD LINE OF THE LOG', 'tone' => 'neutral', 'turn' => 3],
        ]])->save();

        $this->get(route('run.show'))->assertOk()
            ->assertSeeInOrder(['THIRD LINE OF THE LOG', 'SECOND LINE OF THE LOG', 'FIRST LINE OF THE LOG']);
    }

    /** Whatever was fighting has been broken: the run outlives its ICE. */
    public function test_the_screen_renders_after_the_ice_is_broken(): void
    {
        $this->machine(iceLevel: 3);
        $run = $this->begin(noticed: true);

        $run->forceFill(['ice_id' => null, 'ice_integrity' => null])->save();

        $this->get(route('run.show'))->assertOk();
    }

    // ---- and every action, through the real route --------------------------

    /**
     * ONE TURN PER ACTION, over HTTP, against a machine with a guard awake —
     * which is the state that exercises the most code on the way through.
     *
     * A redirect is the success case: `act()` sends a live run back to the
     * screen and a finished one to the map. Anything 5xx is the bug this test
     * exists for.
     */
    public function test_every_action_can_be_taken_over_http(): void
    {
        foreach ([true, false] as $passes) {
            $this->pinTheDice($passes);

            foreach (RunAction::values() as $action) {
                $this->refreshRun(iceLevel: 3);

                $response = $this->post(route('run.act'), ['action' => $action]);

                $response->assertRedirect();
                $this->assertLessThan(
                    500,
                    $response->getStatusCode(),
                    "{$action} came back a server error with every roll ".($passes ? 'passing' : 'failing'),
                );
            }
        }
    }

    /** The same, with nothing awake — the other side of every gate in a turn. */
    public function test_every_action_can_be_taken_against_a_quiet_machine(): void
    {
        foreach ([true, false] as $passes) {
            $this->pinTheDice($passes);

            foreach (RunAction::values() as $action) {
                $this->refreshRun();

                $this->post(route('run.act'), ['action' => $action])->assertRedirect();
            }
        }
    }

    /**
     * BOTH DICE, PINNED, BOTH WAYS.
     *
     * This file used to take what the container gives a real request, dice and
     * all, and a turn that 500s only on an unlucky roll would show up in one
     * run out of however many — which is what happened on 2026-09-23. A smoke
     * test that walks every action has to walk the same path every time, so
     * the two rolls a turn makes are pinned and each test runs the whole list
     * twice: everything passing, then everything failing.
     *
     * `CheckRoller` passes on a roll STRICTLY BELOW the chance, so 0.0 is a
     * check that always lands and 1.0 one that never does. The simulator's own
     * die is an integer range, and its two ends are the same two answers.
     *
     * The Simulator is rebuilt around the pinned roller rather than resolved
     * from the container, or it would hold the random one the provider binds.
     */
    private function pinTheDice(bool $passes): void
    {
        $checks = CheckRoller::fromConfig(static fn (): float => $passes ? 0.0 : 1.0);

        $this->instance(CheckRoller::class, $checks);
        $this->instance(Simulator::class, Simulator::fromConfig(
            static fn (int $min, int $max): int => $passes ? $max : $min,
            app(Resolver::class),
            $checks,
        ));
    }

    /** An action nothing knows is refused by validation, not by a 500. */
    public function test_an_unknown_action_is_a_validation_error(): void
    {
        $this->machine();
        $this->begin();

        $this->post(route('run.act'), ['action' => 'detonate'])
            ->assertSessionHasErrors('action');
    }

    /** The older standalone door out, which now takes an ordinary turn. */
    public function test_the_standalone_egress_route_resolves(): void
    {
        $this->machine();
        $this->begin();

        $this->post(route('run.egress'))->assertRedirect();
    }

    /**
     * AND THE LOCK IS STILL IN FRONT OF EVERYTHING ELSE. A run is a character
     * in jeopardy, so every other screen sends them back to it — asserted here
     * because this is the file that walks the routes.
     */
    public function test_a_live_run_holds_every_other_screen(): void
    {
        $this->machine();
        $this->begin();

        $this->get(route('cyberspace.index'))->assertRedirect(route('run.show'));
        $this->get(route('inventory.index'))->assertRedirect(route('run.show'));
        $this->get(route('skills.index'))->assertRedirect(route('run.show'));
    }

    /** With no run at all, the screen is not a place to be. */
    public function test_the_screen_turns_away_somebody_not_in_a_run(): void
    {
        $this->actingAs($this->character->user)
            ->get(route('run.show'))
            ->assertRedirect(route('cyberspace.index'));
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

    /**
     * Begin a run and act as its owner.
     *
     * The die is pinned through the container rather than handed to a
     * simulator this test built — which is the entire point: a request has to
     * get its collaborators the way a request gets them.
     */
    private function begin(bool $noticed = false): Run
    {
        $this->instance(
            CheckRoller::class,
            CheckRoller::fromConfig(static fn (): float => $noticed ? 1.0 : 0.0),
        );

        $result = app(RunStarter::class)->start($this->character->fresh(), RunPurpose::Extraction);

        // A refusal here is the HELPER failing, not the screen, and it used to
        // arrive as "begin(): return value must be of type Run, null returned"
        // several frames away from the reason. Say the reason.
        $this->assertNotNull(
            $result->run,
            'the run could not be started: '.($result->refusal?->name ?? 'no refusal given'),
        );

        $this->actingAs($this->character->fresh()->user);

        return $result->run;
    }

    /** A machine and a fresh live run, for the next action in the loop. */
    private function refreshRun(?int $iceLevel = null): void
    {
        Run::query()->delete();
        System::query()->delete();

        /*
         * BACK ON THEIR FEET, because the turn before this one was real. With
         * every roll landing, an awake ICE takes eleven points of Synaptic
         * Integrity a turn and empties a starting pool in four — and a
         * character on the waiting screen has been jacked out, so the next
         * run refuses with NotJackedIn and the failure lands here rather than
         * anywhere near the action that caused it.
         *
         * So this puts back everything a turn is allowed to spend: the
         * position, both resources and the floor itself.
         */
        $this->character->forceFill([
            'current_run_id' => null,
            'grid_x' => 8,
            'grid_y' => 8,
            'waiting_until' => null,
            'waiting_cause' => null,
            'current_hitpoints' => $this->character->maximumFor('current_hitpoints'),
            'current_synaptic_integrity' => $this->character->maximumFor('current_synaptic_integrity'),
        ])->save();
        $this->character->refresh();

        $this->machine($iceLevel);
        $this->begin(noticed: $iceLevel !== null);
    }
}
