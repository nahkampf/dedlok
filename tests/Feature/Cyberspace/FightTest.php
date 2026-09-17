<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Characters\WaitingRoom;
use App\Checks\CheckRoller;
use App\Cyberspace\RunAction;
use App\Cyberspace\RunEnding;
use App\Cyberspace\RunPurpose;
use App\Cyberspace\RunStarter;
use App\Cyberspace\Simulator;
use App\Models\Character;
use App\Models\Ice;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Run;
use App\Models\System;
use App\Stats\Resolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/**
 * A turn, and the fight it resolves.
 *
 * Every roll is pinned. An engine tested against real randomness is a test that
 * passes most of the time.
 */
final class FightTest extends TestCase
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

        $this->character->forceFill(['grid_x' => 8, 'grid_y' => 8])->save();
        $this->character->refresh();
    }

    /** A machine guarded by the named piece of ICE. */
    private function machine(int $iceLevel = 1): System
    {
        $ice = Ice::query()->where('level', $iceLevel)->sole();

        return System::factory()->at(8, 8)->grade(1)->withIce($ice)->create(['address' => 'DEADBEEF']);
    }

    /** A die that always rolls this, clamped to the bounds it is handed. */
    private function simulator(int $die): Simulator
    {
        return Simulator::fromConfig(
            static fn (int $min, int $max): int => min($max, max($min, $die)),
            app(Resolver::class),
        );
    }

    /**
     * Begin a run that something noticed.
     *
     * DETECTION IS A ROLL NOW, so a test about the fight has to say which way
     * it went — an unnoticed run has nothing to fight, which is the whole of
     * what stealth buys. Pinning the die at 1.0 fails every evasion check, so
     * every watcher on the machine finds them.
     */
    private function begin(): Run
    {
        $this->instance(CheckRoller::class, CheckRoller::fromConfig(static fn (): float => 1.0));

        return app(RunStarter::class)->start($this->character->fresh(), RunPurpose::Extraction)->run;
    }

    /** And one that nothing saw: every evasion check passes. */
    private function beginUnnoticed(): Run
    {
        $this->instance(CheckRoller::class, CheckRoller::fromConfig(static fn (): float => 0.0));

        return app(RunStarter::class)->start($this->character->fresh(), RunPurpose::Extraction)->run;
    }

    /** Running the icebreaker of that tier, which is the gate on cutting at all. */
    private function runIcebreaker(int $level): void
    {
        $item = Item::query()->where('slug', 'icebreaker-'.$level)->sole();

        InventoryItem::query()->create([
            'character_id' => $this->character->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'running_on_deck_id' => $this->character->equipped_deck_id,
            'started_at' => now(),
        ]);
    }

    // ---- what wakes up -----------------------------------------------------

    public function test_a_guarded_machine_wakes_its_ice_when_the_run_begins(): void
    {
        $system = $this->machine(3);
        $run = $this->begin();

        $this->assertSame($system->ice_id, $run->ice_id);
        $this->assertSame(68, $run->ice_integrity, 'the catalogue says what it starts with');
        $this->assertTrue($run->inAFight());
    }

    /**
     * A RUN NOTHING NOTICED HAS NOTHING TO FIGHT.
     *
     * Detection is what wakes the ICE and the tracer both, so stealth does not
     * make a fight easier — it makes the fight not happen.
     */
    public function test_an_unnoticed_run_wakes_nothing(): void
    {
        $this->machine(3);
        $run = $this->beginUnnoticed();

        $this->assertFalse($run->detected);
        $this->assertNull($run->ice_id, 'the guard is still asleep');
        $this->assertFalse($run->inAFight());
    }

    /** Every watcher gets a look, and any one of them finding you is enough. */
    public function test_a_machine_with_no_ids_can_still_notice_you(): void
    {
        $ice = Ice::query()->where('level', 3)->sole();

        System::factory()->at(8, 8)->grade(1)->withIce($ice)->create([
            'address' => 'DEADBEEF',
            'ids_detection' => 0,
            'tracer_detection' => 0,
        ]);

        $run = $this->begin();

        $this->assertTrue($run->detected, 'the ICE noticed on its own');
        $this->assertSame($ice->id, $run->ice_id);
    }

    /** Nothing watching at all is a machine nobody can be caught in. */
    public function test_a_machine_with_no_watchers_notices_nothing(): void
    {
        System::factory()->at(8, 8)->grade(1)->create([
            'address' => 'DEADBEEF',
            'ids_detection' => 0,
            'tracer_detection' => 0,
        ]);

        $run = $this->begin();

        $this->assertFalse($run->detected);
    }

    public function test_an_unguarded_machine_starts_no_fight(): void
    {
        System::factory()->at(8, 8)->grade(1)->create(['address' => 'DEADBEEF']);

        $run = $this->begin();

        $this->assertNull($run->ice_id);
        $this->assertFalse($run->inAFight());
    }

    // ---- cutting -----------------------------------------------------------

    public function test_an_icebreaker_grinds_the_ice_down(): void
    {
        $this->machine(1);
        $this->runIcebreaker(1);
        $run = $this->begin();

        // The die only decides whether the ICE hits back; a cut always lands.
        $result = $this->simulator(101)->take($this->character->fresh(), $run, RunAction::Attack);

        $this->assertTrue($result->succeeded());
        $this->assertSame(29, $result->run->ice_integrity, 'thirty less the one it cuts for');
        $this->assertSame(1, $result->run->turn);
    }

    /**
     * NO ICEBREAKER, NO DAMAGE — and the log line is the same one a wasted pass
     * gets, because the player is meant to work out which from a pattern.
     */
    public function test_cutting_without_an_icebreaker_does_nothing(): void
    {
        $this->machine(1);
        $run = $this->begin();

        $result = $this->simulator(101)->take($this->character->fresh(), $run, RunAction::Attack);

        $this->assertSame(30, $result->run->ice_integrity, 'untouched');
        $this->assertSame(1, $result->run->turn, 'and the turn is spent anyway');
    }

    /**
     * THE OPERATING SYSTEM GATE, and it never announces itself: the attack
     * simply fails, wearing the same line as any other wasted pass.
     */
    public function test_an_unspeakable_operating_system_makes_every_cut_fail(): void
    {
        $this->machine(1);
        $this->runIcebreaker(1);
        $run = $this->begin();

        $run->forceFill(['os_locked' => true])->save();

        $result = $this->simulator(101)->take($this->character->fresh(), $run->fresh(), RunAction::Attack);

        $this->assertSame(30, $result->run->ice_integrity);

        $lines = array_column($result->log, 'text');
        $this->assertNotEmpty(array_filter($lines, static fn (string $l): bool => str_contains($l, 'nothing to cut')));

        foreach ($lines as $line) {
            $this->assertStringNotContainsStringIgnoringCase('operating system', $line);
        }
    }

    public function test_breaking_the_ice_ends_the_fight_and_not_the_run(): void
    {
        $this->machine(1);
        $this->runIcebreaker(12);   // 20 damage against 30 integrity
        $run = $this->begin();

        $sim = $this->simulator(101);
        $sim->take($this->character->fresh(), $run, RunAction::Attack);
        $result = $sim->take($this->character->fresh(), $run->fresh(), RunAction::Attack);

        $this->assertNull($result->run->ice_id, 'nothing left fighting');
        $this->assertFalse($result->ended(), 'but the run carries on');
        $this->assertTrue($result->run->isLive());
    }

    // ---- being hit ---------------------------------------------------------

    /** A MISS DOES NOTHING AT ALL, which is what makes a weak guard survivable. */
    public function test_a_missed_swing_costs_nothing(): void
    {
        $this->machine(1);
        $run = $this->begin();

        $before = $this->character->fresh()->current_synaptic_integrity;
        $this->simulator(101)->take($this->character->fresh(), $run, RunAction::Hold);

        $this->assertSame($before, $this->character->fresh()->current_synaptic_integrity);
    }

    public function test_a_landed_swing_takes_synaptic_integrity(): void
    {
        $this->machine(1);
        $run = $this->begin();

        $before = $this->character->fresh()->current_synaptic_integrity;
        $this->simulator(1)->take($this->character->fresh(), $run, RunAction::Hold);

        $this->assertSame($before - 7, $this->character->fresh()->current_synaptic_integrity);
    }

    /**
     * THE ORDER EVERY BAD ENDING FOLLOWS: damage, consequence, ejection, and
     * only then the waiting screen.
     */
    public function test_being_beaten_ejects_first_and_then_puts_you_down(): void
    {
        $this->machine(1);
        $run = $this->begin();

        $this->character->forceFill(['current_synaptic_integrity' => 3])->save();

        $sim = $this->simulator(1);
        $result = $sim->take($this->character->fresh(), $run, RunAction::Hold);

        $this->assertSame(RunEnding::Defeat, $result->ending);

        $sim->resolveEnding($this->character->fresh(), $result->run, $result->ending);

        $character = $this->character->fresh();

        $this->assertSame(0, $character->current_synaptic_integrity);
        $this->assertFalse($character->isJackedIn(), 'ejected BEFORE the screen');
        $this->assertTrue(app(WaitingRoom::class)->isDown($character));
        $this->assertNull($character->current_run_id, 'and the run is over');
        $this->assertSame('defeat', $character->waiting_cause['reason']);
        $this->assertSame('ICE I', $character->waiting_cause['ice']);
    }

    // ---- leaving -----------------------------------------------------------

    /** Leaving is always on the list, and it spends the turn like anything else. */
    public function test_egress_ends_the_run_on_the_turn_it_is_chosen(): void
    {
        $this->machine(12);
        $run = $this->begin();

        $result = $this->simulator(1)->take($this->character->fresh(), $run, RunAction::Egress);

        $this->assertSame(RunEnding::Egress, $result->ending);
        $this->assertFalse($result->run->fresh()->isLive());
        $this->assertNull($this->character->fresh()->current_run_id);
    }

    /** Leaving happens before the machine's half of the turn: no parting shot. */
    public function test_leaving_does_not_take_a_hit_on_the_way_out(): void
    {
        $this->machine(12);
        $run = $this->begin();

        $before = $this->character->fresh()->current_synaptic_integrity;
        $this->simulator(1)->take($this->character->fresh(), $run, RunAction::Egress);

        $this->assertSame($before, $this->character->fresh()->current_synaptic_integrity);
    }

    // ---- the account -------------------------------------------------------

    public function test_the_log_accumulates_turn_by_turn(): void
    {
        $this->machine(1);
        $run = $this->begin();

        $sim = $this->simulator(101);
        $sim->take($this->character->fresh(), $run, RunAction::Hold);
        $sim->take($this->character->fresh(), $run->fresh(), RunAction::Hold);

        $log = $run->fresh()->log;

        $this->assertNotEmpty($log);

        // Turn ZERO is arriving. It is not a turn anybody spent — it is what
        // happened on the way in, and it belongs at the top of the transcript
        // because it decides whether the rest of it is a fight.
        $this->assertSame(0, $log[0]['turn'], 'arriving is turn zero');
        $this->assertSame(2, end($log)['turn'], 'newest last, the way a transcript reads');
    }
}
