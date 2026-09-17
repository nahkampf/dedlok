<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Characters\DownedCause;
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
use App\Models\SystemContent;
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

    /**
     * A machine guarded by the named piece of ICE, and tracing at a grade 1's
     * rate.
     *
     * The tracer is set explicitly because the factory defaults every defence
     * to zero — nothing becomes dangerous by being forgotten — so a fixture
     * that wanted one has to say so.
     */
    private function machine(int $iceLevel = 1): System
    {
        $ice = Ice::query()->where('level', $iceLevel)->sole();

        return System::factory()->at(8, 8)->grade(1)
            ->withIce($ice)
            ->withTracer(2, 4)
            ->create(['address' => 'DEADBEEF']);
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
    /**
     * Turn off both standing instructions.
     *
     * A test about what happens when you are nearly dead has to get past the
     * threshold that exists to stop you being nearly dead — which is the
     * thresholds working, not the test fighting them.
     */
    private function ignoreThresholds(): void
    {
        $this->character->forceFill(['egress_trace_at' => 0, 'egress_si_at' => 0])->save();
        $this->character->refresh();
    }

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
        $this->ignoreThresholds();
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

    // ---- the trace ---------------------------------------------------------

    /**
     * DETECTION IS WHAT WAKES THE TRACER, so a run nothing saw is a run nothing
     * is following — the other half of what stealth buys, alongside not having
     * to fight.
     */
    public function test_an_unnoticed_run_is_not_being_traced(): void
    {
        $this->machine(1);
        $run = $this->beginUnnoticed();

        $this->simulator(101)->take($this->character->fresh(), $run, RunAction::Hold);

        $this->assertSame(0, $run->fresh()->trace);
        $this->assertNull($run->fresh()->turnsUntilTraced(), 'and nothing to count down');
    }

    public function test_the_trace_walks_a_little_further_every_turn(): void
    {
        // A grade 1 tracer moves 4% a turn.
        $this->machine(1);
        $run = $this->begin();

        $sim = $this->simulator(101);
        $sim->take($this->character->fresh(), $run, RunAction::Hold);
        $this->assertSame(4, $run->fresh()->trace);

        $sim->take($this->character->fresh(), $run->fresh(), RunAction::Hold);
        $this->assertSame(8, $run->fresh()->trace);
    }

    /** The number the egress decision is made on. */
    public function test_it_says_how_many_turns_are_left(): void
    {
        $this->machine(1);
        $run = $this->begin();

        $this->assertSame(25, $run->turnsUntilTraced(), '100 at 4% a turn');

        $this->simulator(101)->take($this->character->fresh(), $run, RunAction::Hold);

        $this->assertSame(24, $run->fresh()->turnsUntilTraced());
    }

    /** A machine with no tracer follows nobody, however loudly they came in. */
    public function test_a_machine_with_no_tracer_never_traces(): void
    {
        $ice = Ice::query()->where('level', 1)->sole();

        System::factory()->at(8, 8)->grade(1)->withIce($ice)->create([
            'address' => 'DEADBEEF',
            'tracer_detection' => 0,
            'tracer_rate' => 0,
        ]);

        $run = $this->begin();
        $this->simulator(101)->take($this->character->fresh(), $run, RunAction::Hold);

        $this->assertTrue($run->fresh()->detected, 'the ICE saw them');
        $this->assertSame(0, $run->fresh()->trace, 'but nothing is following');
    }

    /** It lands once and stops climbing. Heat and burning the chain are not built. */
    public function test_a_completed_trace_lands_once(): void
    {
        $this->machine(1);
        $this->ignoreThresholds();
        $run = $this->begin();
        $run->forceFill(['trace' => 98])->save();

        $sim = $this->simulator(101);
        $result = $sim->take($this->character->fresh(), $run->fresh(), RunAction::Hold);

        $this->assertSame(100, $result->run->trace);
        $this->assertNotEmpty(array_filter(
            array_column($result->log, 'text'),
            static fn (string $line): bool => str_contains($line, 'reaches you'),
        ));

        // And it does not climb past, nor announce itself twice.
        $again = $sim->take($this->character->fresh(), $run->fresh(), RunAction::Hold);

        $this->assertSame(100, $again->run->trace);
        $this->assertEmpty(array_filter(
            array_column($again->log, 'text'),
            static fn (string $line): bool => str_contains($line, 'reaches you'),
        ));
    }

    public function test_the_screen_draws_the_chain(): void
    {
        $this->machine(1);
        $this->ignoreThresholds();
        $run = $this->begin();
        $run->forceFill(['trace' => 40])->save();

        $this->actingAs($this->character->fresh()->user)
            ->get(route('run.show'))
            ->assertOk()
            ->assertSee('trace__node', false)
            ->assertSee('--v:0.4', false)
            ->assertSee('turns before it reaches you', false);
    }

    // ---- the standing instructions -----------------------------------------

    /**
     * THEY ACT, THEY DO NOT WARN — whatever the player asked for this turn.
     *
     * Not the gentler rule, deliberately: a threshold that only warned would
     * leave an unresponsive player in a run that never resolves. It is the
     * instruction they left for exactly this moment.
     */
    public function test_the_trace_threshold_pulls_you_out(): void
    {
        $this->machine(1);
        $run = $this->begin();
        $run->forceFill(['trace' => 72])->save();   // the default mark is 70

        $result = $this->simulator(101)->take($this->character->fresh(), $run->fresh(), RunAction::Attack);

        $this->assertSame(RunEnding::Egress, $result->ending, 'it left, though Cut was asked for');
        $this->assertNull($this->character->fresh()->current_run_id);
    }

    public function test_the_synaptic_threshold_pulls_you_out(): void
    {
        $this->machine(1);
        $run = $this->begin();

        $max = $this->character->maximumFor('current_synaptic_integrity');
        $this->character->forceFill(['current_synaptic_integrity' => (int) floor($max * 0.2)])->save();

        $result = $this->simulator(101)->take($this->character->fresh(), $run->fresh(), RunAction::Hold);

        $this->assertSame(RunEnding::Egress, $result->ending);
    }

    /** Zero turns one off, without needing a second column to say whether it is on. */
    public function test_a_threshold_of_zero_never_fires(): void
    {
        $this->machine(1);
        $this->ignoreThresholds();
        $run = $this->begin();
        $run->forceFill(['trace' => 99])->save();

        $result = $this->simulator(101)->take($this->character->fresh(), $run->fresh(), RunAction::Hold);

        $this->assertNull($result->ending, 'it stayed');
    }

    // ---- what a completed trace costs --------------------------------------

    /** Heat is persistent and belongs to the person. One point, for now. */
    public function test_a_completed_trace_puts_heat_on_the_character(): void
    {
        $this->machine(1);
        $this->ignoreThresholds();
        $run = $this->begin();
        $run->forceFill(['trace' => 98])->save();

        $before = (int) $this->character->fresh()->heat;

        $this->simulator(101)->take($this->character->fresh(), $run->fresh(), RunAction::Hold);

        $this->assertSame($before + 1, (int) $this->character->fresh()->heat);
    }

    /** And only once, however long they stay afterwards. */
    public function test_heat_lands_once_per_trace(): void
    {
        $this->machine(1);
        $this->ignoreThresholds();
        $run = $this->begin();
        $run->forceFill(['trace' => 98])->save();

        $sim = $this->simulator(101);
        $sim->take($this->character->fresh(), $run->fresh(), RunAction::Hold);
        $sim->take($this->character->fresh(), $run->fresh(), RunAction::Hold);

        $this->assertSame(1, (int) $this->character->fresh()->heat);
    }

    // ---- walking the chain -------------------------------------------------

    /**
     * A TRACE WALKS THE CHAIN, one hop a turn, and a hop once passed is behind
     * it for good.
     *
     * That last part is the whole difference between a long chain being slow
     * and a long chain being impassable: rolling every hop every turn would
     * need ten simultaneous successes for ever, rather than ten successes
     * eventually.
     *
     * Routing is not built, so the chain is stubbed here — this pins the walk
     * rather than the route, and `Run::routingGrades()` is the one method that
     * changes when backdoors land.
     */
    public function test_the_trace_attempts_one_hop_a_turn_and_keeps_what_it_passes(): void
    {
        $this->machine(1);
        $this->ignoreThresholds();
        $run = $this->begin();

        // A grade 9 hop lets it through 20% of the time; a 1 always does.
        $chained = new class extends Run
        {
            protected $table = 'runs';

            public function routingGrades(): array
            {
                return [9, 1];
            }
        };

        $chained->setRawAttributes($run->getAttributes());
        $chained->exists = true;

        // Held at the first hop: the roll misses and nothing moves.
        $this->simulator(101)->take($this->character->fresh(), $chained, RunAction::Hold);
        $this->assertSame(0, $chained->trace, 'held at the first hop');
        $this->assertSame(0, $chained->trace_hop);

        // Through it, and the turn ends there.
        $this->simulator(1)->take($this->character->fresh(), $chained, RunAction::Hold);
        $this->assertSame(1, $chained->trace_hop, 'one hop, one turn');
        $this->assertSame(4, $chained->trace);

        // The second hop is a grade 1, so even the worst roll passes it — and
        // the grade 9 behind it is never asked again.
        $this->simulator(101)->take($this->character->fresh(), $chained, RunAction::Hold);
        $this->assertSame(2, $chained->trace_hop, 'past the chain');
        $this->assertSame(8, $chained->trace);

        // And past the end it comes on unopposed.
        $this->simulator(101)->take($this->character->fresh(), $chained, RunAction::Hold);
        $this->assertSame(12, $chained->trace);
    }

    /** No chain is the unopposed case from the first turn. */
    public function test_an_empty_chain_never_holds_the_trace_up(): void
    {
        $this->machine(1);
        $this->ignoreThresholds();
        $run = $this->begin();

        $this->assertSame([], $run->routingGrades());

        $this->simulator(101)->take($this->character->fresh(), $run, RunAction::Hold);

        $this->assertSame(4, $run->fresh()->trace);
        $this->assertSame(0, $run->fresh()->trace_hop, 'nothing to be past');
    }

    // ---- what a defeat actually costs --------------------------------------

    /**
     * A DEFEAT APPLIES THE ICE'S OWN TAGS, in the order every bad ending
     * follows: damage already landed (that is why we are here), so now the
     * consequence, then the ejection, then the waiting screen. This is the
     * end-to-end path — DefeatResolverTest pins each tag on its own.
     */
    public function test_a_defeat_applies_the_ices_tags_before_the_waiting_screen(): void
    {
        $this->machine(9);
        $this->ignoreThresholds();
        $this->equipReadyDeck($this->character);

        // The catalogue's own tags are written by cyberspace:tag-ice, a
        // console command and not a migration — so a test database's ICE
        // rows carry no tags until told to. This is what the live one does.
        Ice::query()->where('level', 9)->update([
            'defeat_tags' => json_encode([
                ['tag' => 'flatline', 'level' => 1],
                ['tag' => 'skim', 'level' => 1],
            ]),
        ]);

        $run = $this->begin();

        $this->character->fresh()->forceFill(['current_synaptic_integrity' => 1, 'creds' => 1000])->save();

        $sim = $this->simulator(1); // every roll wins
        $result = $sim->take($this->character->fresh(), $run->fresh(), RunAction::Hold);

        $this->assertSame(RunEnding::Defeat, $result->ending);

        $sim->resolveEnding($this->character->fresh(), $result->run, $result->ending);

        $character = $this->character->fresh();
        $cause = DownedCause::fromArray($character->waiting_cause);

        $this->assertNotEmpty($cause->effects, 'the tags actually did something, not just named themselves');
        $this->assertSame('ICE IX', $cause->ice);
    }

    // ---- extraction ---------------------------------------------------------

    /**
     * THE WHOLE HAUL, ONE TURN. This is the end-to-end path — ExtractorTest
     * pins the transfer itself, this pins that a turn spent Extracting
     * actually reaches it.
     */
    public function test_extracting_takes_the_nodes_contents(): void
    {
        $system = $this->machine(1);
        SystemContent::query()->create(['system_id' => $system->id, 'kind' => 'creds', 'amount' => 500]);
        $this->ignoreThresholds();
        $run = $this->begin();

        $result = $this->simulator(101)->take($this->character->fresh(), $run->fresh(), RunAction::Extract);

        $this->assertSame(500, $this->character->fresh()->creds);
        $this->assertTrue($result->run->extracted);
        $this->assertNotEmpty(array_filter(
            array_column($result->log, 'text'),
            static fn (string $l): bool => str_contains($l, '500 creds'),
        ));
    }

    /**
     * TOUCHING THE CORE IS THE IDS'S SECOND CHANCE, independent of what
     * happened on approach. Begun UNNOTICED, then the extraction itself is
     * what gets caught.
     */
    public function test_extraction_can_be_the_thing_that_gets_you_noticed(): void
    {
        $system = $this->machine(9); // grade 1 machine but a real IDS via the ICE row's own detection value is not enough alone; force a real ids_detection too
        $system->forceFill(['ids_detection' => 50])->save();
        SystemContent::query()->create(['system_id' => $system->id, 'kind' => 'creds', 'amount' => 10]);
        $this->ignoreThresholds();
        $this->equipReadyDeck($this->character);

        $run = $this->beginUnnoticed();
        $this->assertFalse($run->detected);

        // beginUnnoticed() bound CheckRoller to always evade, for the approach
        // roll. Rebind it here to always FAIL evasion, so this turn's core
        // touch is caught — simulator()'s own die only ever pins the OTHER
        // rolls (damage, surge, and so on), never CheckRoller.
        $this->instance(CheckRoller::class, CheckRoller::fromConfig(static fn (): float => 1.0));
        $result = $this->simulator(101)->take($this->character->fresh(), $run->fresh(), RunAction::Extract);

        $this->assertTrue($result->run->detected, 'caught on the core touch, not on approach');
        $this->assertSame($system->ice_id, $result->run->ice_id);

        // And the extraction still succeeded — being noticed is a consequence
        // of touching the core, not a block on the act itself.
        $this->assertSame(10, $this->character->fresh()->creds);
    }

    /** An unspeakable operating system fails silently, the same line a wasted cut wears. */
    public function test_extraction_fails_silently_against_an_unspeakable_os(): void
    {
        $system = $this->machine(1);
        SystemContent::query()->create(['system_id' => $system->id, 'kind' => 'creds', 'amount' => 500]);
        $this->ignoreThresholds();
        $run = $this->begin();
        $run->forceFill(['os_locked' => true])->save();

        $result = $this->simulator(101)->take($this->character->fresh(), $run->fresh(), RunAction::Extract);

        $this->assertSame(0, $this->character->fresh()->creds, 'nothing came out');
        $this->assertFalse($result->run->extracted);

        foreach ($result->log as $line) {
            $this->assertStringNotContainsStringIgnoringCase('operating system', $line['text']);
        }
    }

    /** An empty node wastes the turn the same silent way — nothing distinguishes the two causes. */
    public function test_extraction_finds_nothing_on_an_empty_node(): void
    {
        $this->machine(1);
        $this->ignoreThresholds();
        $run = $this->begin();

        $result = $this->simulator(101)->take($this->character->fresh(), $run->fresh(), RunAction::Extract);

        $this->assertFalse($result->run->extracted);
        $this->assertSame(1, $result->run->turn, 'the turn is spent anyway');
    }

    /**
     * COMPLETED, NOT EGRESS, once extraction actually took something — "they
     * did what they came to do" is only true from that point on.
     */
    public function test_egress_after_a_successful_extraction_ends_as_completed(): void
    {
        $system = $this->machine(1);
        SystemContent::query()->create(['system_id' => $system->id, 'kind' => 'creds', 'amount' => 50]);
        $this->ignoreThresholds();
        $run = $this->begin();

        $sim = $this->simulator(101);
        $afterExtract = $sim->take($this->character->fresh(), $run->fresh(), RunAction::Extract);
        $result = $sim->take($this->character->fresh(), $afterExtract->run->fresh(), RunAction::Egress);

        $this->assertSame(RunEnding::Completed, $result->ending);
    }

    /** Leaving empty-handed is still a plain Egress. */
    public function test_egress_with_nothing_taken_stays_plain_egress(): void
    {
        $this->machine(1);
        $this->ignoreThresholds();
        $run = $this->begin();

        $result = $this->simulator(101)->take($this->character->fresh(), $run->fresh(), RunAction::Egress);

        $this->assertSame(RunEnding::Egress, $result->ending);
    }

    // ---- a file costs turns, and the ICE keeps swinging while it does -------

    /**
     * THE WHOLE POINT: a multi-turn pull is spent one ordinary turn at a
     * time, so the ICE that woke up on the touch gets to act on every turn
     * the transfer keeps running — the same as it would against Attack or
     * Hold. The fixture's fitted NIC resolves to 50 QB of bandwidth a turn
     * (see CarriesAWorkingDeck), so a 120 QB file takes three turns: begin,
     * one continue, and one that completes it.
     */
    public function test_a_multi_turn_transfer_still_exposes_every_turn_to_the_ice(): void
    {
        $system = $this->machine(1); // ICE I: 15% to hit, 7 damage — simulator(1) always lands it
        SystemContent::query()->create([
            'system_id' => $system->id, 'kind' => 'data', 'label' => 'Ledger',
            'size_qb' => 120, 'value_sealed' => 10, 'value_broken' => 20,
        ]);
        $this->ignoreThresholds();
        $run = $this->begin();
        $sim = $this->simulator(1);

        $before = $this->character->fresh()->current_synaptic_integrity;

        $first = $sim->take($this->character->fresh(), $run->fresh(), RunAction::Extract);
        $this->assertSame(70, $first->run->extracting_qb);
        $this->assertFalse($first->run->extracted);

        $second = $sim->take($this->character->fresh(), $first->run->fresh(), RunAction::Extract);
        $this->assertSame(20, $second->run->extracting_qb);
        $this->assertFalse($second->run->extracted);

        $third = $sim->take($this->character->fresh(), $second->run->fresh(), RunAction::Extract);
        $this->assertNull($third->run->extracting_qb, 'the countdown clears once the file lands');
        $this->assertTrue($third->run->extracted);

        // Three turns spent, three swings landed — nothing about a transfer
        // running skips the machine's half of the turn.
        $this->assertSame($before - 21, $this->character->fresh()->current_synaptic_integrity);

        $this->assertSame(
            1,
            InventoryItem::where('character_id', $this->character->id)
                ->whereHas('item', fn ($q) => $q->where('slug', 'encrypted-data-dump'))
                ->count(),
        );
    }

    /** Spending a turn on Attack instead pauses a pull rather than losing it. */
    public function test_attacking_mid_transfer_leaves_its_progress_untouched(): void
    {
        $system = $this->machine(1);
        SystemContent::query()->create([
            'system_id' => $system->id, 'kind' => 'data', 'label' => 'Ledger',
            'size_qb' => 120, 'value_sealed' => 10, 'value_broken' => 20,
        ]);
        $this->ignoreThresholds();
        $run = $this->begin();
        $sim = $this->simulator(101); // misses every ICE swing, so SI is not what this test is about

        $afterBegin = $sim->take($this->character->fresh(), $run->fresh(), RunAction::Extract);
        $this->assertSame(70, $afterBegin->run->extracting_qb);

        $afterAttack = $sim->take($this->character->fresh(), $afterBegin->run->fresh(), RunAction::Attack);
        $this->assertSame(70, $afterAttack->run->extracting_qb, 'a turn spent elsewhere does not spend the transfer too');

        $afterContinue = $sim->take($this->character->fresh(), $afterAttack->run->fresh(), RunAction::Extract);
        $this->assertSame(20, $afterContinue->run->extracting_qb, 'picking it back up resumes from where it was');
    }

    /**
     * A slower raider loses the race exactly the way an instant haul already
     * does — `Extractor::takeFiles()`'s own "nothing to take" answer, reached
     * this time from a countdown finishing rather than a single touch.
     */
    public function test_a_transfer_that_finishes_after_someone_else_already_took_it(): void
    {
        $system = $this->machine(1);
        SystemContent::query()->create([
            'system_id' => $system->id, 'kind' => 'data', 'label' => 'Ledger',
            'size_qb' => 60, 'value_sealed' => 10, 'value_broken' => 20,
        ]);
        $this->ignoreThresholds();
        $run = $this->begin();
        $sim = $this->simulator(101);

        $afterBegin = $sim->take($this->character->fresh(), $run->fresh(), RunAction::Extract);
        $this->assertSame(10, $afterBegin->run->extracting_qb);

        // Somebody else's raid finishes first, in between this run's turns.
        SystemContent::query()->where('system_id', $system->id)->where('kind', 'data')->delete();

        $result = $sim->take($this->character->fresh(), $afterBegin->run->fresh(), RunAction::Extract);

        $this->assertNull($result->run->extracting_qb);
        $this->assertFalse($result->run->extracted);
        $this->assertNotEmpty(array_filter(
            array_column($result->log, 'text'),
            static fn (string $l): bool => str_contains($l, 'stripped bare'),
        ));
    }
}
