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
use App\Models\Entity;
use App\Models\EntityLedgerEntry;
use App\Models\Ice;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Run;
use App\Models\System;
use App\Models\SystemContent;
use App\Standing\StandingReason;
use App\Stats\Resolver;
use App\Support\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
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

        // Files here run to thousands of QB; storage is not what these test.
        config(['decks.onboard_storage' => 1_000_000]);
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

    /**
     * Let the next check PASS.
     *
     * `begin()` pins the die so every check fails, which is how a test gets a
     * detected run — and egress is a check now, so a test that wants to leave
     * has to say which way that roll went. Rebinding rather than passing a
     * flag keeps the pinning in one idiom.
     */
    private function everyCheckPasses(): void
    {
        $this->instance(CheckRoller::class, CheckRoller::fromConfig(static fn (): float => 0.0));
    }

    /** And the mirror, for a test about being held. */
    private function everyCheckFails(): void
    {
        $this->instance(CheckRoller::class, CheckRoller::fromConfig(static fn (): float => 1.0));
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

    public function test_the_log_names_your_icebreaker_in_a_green_tag_and_the_ice_in_a_grey_one(): void
    {
        $this->machine(1);
        $this->runIcebreaker(1);
        $run = $this->begin();

        // The ICE misses (the die is high): your cut, then its failed attempt.
        $log = $this->simulator(101)->take($this->character->fresh(), $run, RunAction::Attack)->run->log;

        $cut = collect($log)->first(fn (array $l): bool => ($l['before'] ?? null) === 'Your');
        $this->assertSame('ok', $cut['tag_tone']);
        $this->assertSame(Item::query()->where('slug', 'icebreaker-1')->sole()->displayName(), $cut['tag']);
        $this->assertSame('finds an intrusion vector and pushes a disruptive payload through, causing 1 system integrity loss.', $cut['text']);

        $miss = collect($log)->first(fn (array $l): bool => ($l['text'] ?? '') === 'attempts to find an open attack vector, but fails.');
        $this->assertSame('', $miss['tag_tone'], 'grey: no colour');
        $this->assertSame('ICE I', $miss['tag']);
    }

    public function test_the_last_blow_says_the_ice_is_disabled(): void
    {
        $this->machine(1);
        $this->runIcebreaker(1);
        $run = $this->begin();
        $run->forceFill(['ice_integrity' => 1])->save();

        $log = $this->simulator(101)->take($this->character->fresh(), $run->fresh(), RunAction::Attack)->run->log;

        $this->assertTrue(collect($log)->contains(fn (array $l): bool => $l['text'] === 'The ICE freezes, glitches for a few milliseconds and then vanishes, stuck in a crash loop. It is now disabled.'));
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
     * A SHUT CORE DOES NOT STOP A CUT.
     *
     * An icebreaker fighting an ICE is one program against another, and needs
     * no vocabulary — the machine's operating system only matters when the
     * machine itself is being told to do something, which is the core's roll
     * and not this one's. It used to gate both, which made an unfamiliar
     * system a wall rather than a difficulty.
     */
    public function test_a_shut_core_does_not_stop_a_cut(): void
    {
        $this->machine(1);
        $this->runIcebreaker(1);
        $run = $this->begin();

        $run->forceFill(['os_locked' => true])->save();

        $result = $this->simulator(101)->take($this->character->fresh(), $run->fresh(), RunAction::Attack);

        $this->assertLessThan(30, $result->run->ice_integrity, 'the cut lands anyway');
        $this->assertTrue($result->run->os_locked, 'and the core is no more open for it');

        foreach (array_column($result->log, 'text') as $line) {
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
        $this->everyCheckPasses();

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
        $this->everyCheckPasses();

        $before = $this->character->fresh()->current_synaptic_integrity;
        $this->simulator(1)->take($this->character->fresh(), $run, RunAction::Egress);

        $this->assertSame($before, $this->character->fresh()->current_synaptic_integrity);
    }

    /**
     * AND A FAILED ONE DOES TAKE THE HIT, because it spent the turn.
     *
     * The other half of the same rule: getting out early is what buys you the
     * machine's half of the turn, so being held costs exactly what holding
     * would have — one more swing, and the trace a little further along.
     */
    public function test_being_held_costs_the_turn_like_any_other(): void
    {
        // ICE I: 7 damage, which a pinned die always lands. ICE XII would
        // simply kill them, and a defeat is a different test.
        $this->machine(1);
        $this->ignoreThresholds();
        $run = $this->begin();
        $this->everyCheckFails();

        $before = $this->character->fresh()->current_synaptic_integrity;
        $result = $this->simulator(1)->take($this->character->fresh(), $run, RunAction::Egress);

        $this->assertNull($result->ending, 'still in it');
        $this->assertTrue($result->run->fresh()->isLive());
        $this->assertLessThan(
            $before,
            $this->character->fresh()->current_synaptic_integrity,
            'ICE XII swung on the turn the egress failed',
        );
        $this->assertNotEmpty(array_filter(
            array_column($result->log, 'text'),
            static fn (string $line): bool => str_contains($line, 'does not let go'),
        ));
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
            ->assertSee($this->turnsLeft($run), false);
    }

    /**
     * How long the trace still needs, in the screen's own words.
     *
     * Worked out server-side by `Run::turnsUntilTraced()` and read through the
     * config rather than matched as a phrase: what this test is about is that
     * the chain carries the countdown, not how the countdown is worded.
     */
    private function turnsLeft(Run $run): string
    {
        $turns = (int) $run->fresh()->turnsUntilTraced();

        return Text::plural('cyberspace.run.trace_turns', $turns, ['count' => $turns]);
    }

    // ---- the screen ----------------------------------------------------------

    private function runScreen(): TestResponse
    {
        return $this->actingAs($this->character->fresh()->user)->get(route('run.show'))->assertOk();
    }

    public function test_the_run_overview_is_a_table_with_the_turn_large_and_green(): void
    {
        $this->machine(1);
        $this->ignoreThresholds();
        $this->begin();

        $this->runScreen()
            ->assertSeeText('Run overview')
            ->assertDontSeeText('In the system')
            ->assertSeeText('Coordinates')
            ->assertSeeText('Address')
            ->assertSeeText('Node type')
            ->assertSeeText('System name')
            ->assertSeeText('Run type')
            ->assertSee('DEADBEEF')
            ->assertSee('font-size:var(--fs-xl);line-height:1;color:var(--ok)', false)
            ->assertSeeText('Turn 0');
    }

    public function test_the_trace_counts_down_to_trace_complete(): void
    {
        $this->machine(1);
        $this->ignoreThresholds();
        $run = $this->begin();
        $run->forceFill(['trace' => 40])->save();

        $this->runScreen()
            ->assertSeeText('until trace complete')
            ->assertDontSeeText('catches up to you');
    }

    public function test_the_ice_is_a_table_with_a_bar_that_actually_draws_and_no_turn_marker(): void
    {
        $this->machine(1);
        $this->ignoreThresholds();
        $run = $this->begin();
        $ice = Ice::query()->where('level', 1)->sole();

        $html = $this->runScreen()
            ->assertSeeText('ICE grade')
            ->assertSeeText('Integrity')
            ->assertSeeText($run->ice_integrity.' / '.$ice->integrity)
            ->getContent();

        $this->assertStringContainsString('<div class="meter__fill"></div>', $html, 'a <div>: an inline <span> has no height and draws nothing');
        $this->assertStringNotContainsString('<span class="meter__fill">', $html);
        // The ICE panel no longer carries a turn of its own: the only "Turn" in the page's panels is the overview's.
        $this->assertSame(1, preg_match_all('/Turn \d+<\/span>/', preg_replace('/<p class="micro".*?<\/p>/s', '', $html)), 'one turn marker outside the log');
    }

    public function test_the_actions_panel_holds_evasion_the_buttons_and_the_guard_warning(): void
    {
        $this->machine(1);
        $this->ignoreThresholds();
        $this->begin();

        $this->runScreen()
            ->assertSeeText('Actions')
            ->assertSeeText('Evasion chance for egress:')
            ->assertSeeText('Continue run')
            ->assertSeeText('The ICE has been activated and is guarding the core. You must break the ICE before continuing, or attempt an egress.')
            ->assertDontSeeText('Leaving is always possible')
            ->assertDontSeeText('Chance of getting out');
    }

    public function test_the_overview_shows_the_players_thresholds_and_the_old_wording_is_gone(): void
    {
        $this->machine(1);
        $this->character->forceFill(['egress_trace_at' => 70, 'egress_si_at' => 0])->save();
        $this->begin();

        $this->runScreen()
            ->assertSeeText('Run overview')
            ->assertDontSeeText('System overview')
            ->assertSeeText('Egress at trace')
            ->assertSeeText('70%')
            ->assertSeeText('Egress below SI')
            ->assertSeeText('Off')
            ->assertDontSeeText('How this is worked out');
    }

    public function test_the_actions_panel_wears_a_hazard_stripe_on_or_past_a_trace_threshold(): void
    {
        $this->machine(1);
        $this->character->forceFill(['egress_trace_at' => 50, 'egress_si_at' => 0])->save();
        $run = $this->begin();

        $this->runScreen()->assertDontSee('hazard-stripe', false);

        $run->forceFill(['trace' => 49])->save();
        $this->runScreen()->assertDontSee('hazard-stripe', false);

        // ON the mark counts, as it does for the turn that acts on it.
        $run->forceFill(['trace' => 50])->save();
        $this->runScreen()->assertSee('hazard-stripe hazard-stripe--thin', false);
    }

    public function test_the_stripe_follows_the_games_own_test_for_integrity_which_is_below_not_on(): void
    {
        $this->machine(1);
        $this->character->forceFill(['egress_trace_at' => 0, 'egress_si_at' => 50])->save();
        $this->begin();
        $max = (int) $this->character->fresh()->maximumFor('current_synaptic_integrity');

        // Exactly on the mark is not a breach: the next turn would not pull them out, so the screen does not say it would.
        $this->character->forceFill(['current_synaptic_integrity' => (int) ($max / 2)])->save();
        $onTheMark = ((int) ($max / 2) / $max) * 100 < 50;
        $this->assertSame($onTheMark, str_contains($this->runScreen()->getContent(), 'hazard-stripe'));

        $this->character->forceFill(['current_synaptic_integrity' => max(1, (int) ($max * 0.4))])->save();
        $this->runScreen()->assertSee('hazard-stripe hazard-stripe--thin', false);
    }

    public function test_a_threshold_set_to_zero_is_off_and_never_stripes(): void
    {
        $this->machine(1);
        $this->character->forceFill(['egress_trace_at' => 0, 'egress_si_at' => 0, 'current_synaptic_integrity' => 1])->save();
        $run = $this->begin();
        $run->forceFill(['trace' => 99])->save();

        $this->runScreen()->assertDontSee('hazard-stripe', false);
    }

    public function test_the_evasion_working_floats_from_the_egress_button_and_not_the_bar(): void
    {
        $this->machine(1);
        $this->ignoreThresholds();
        $this->begin();

        $html = $this->runScreen()
            ->assertSeeText('Sum, divided by')
            ->assertSeeText('Modifiers')
            ->assertSeeText('Opposition')
            ->assertDontSeeText('What moves it')
            ->assertDontSeeText('What you are up against')
            ->assertDontSeeText('No attempt is ever hopeless')
            ->assertDontSeeText('How this is worked out')
            ->getContent();

        $this->assertDoesNotMatchRegularExpression('/\d\s+over\s+\d/', strip_tags($html), 'the old "62.5 over 4" label is gone');

        // Exactly one thing points at the card: the Egress button, hover and focus only.
        $this->assertSame(1, substr_count($html, 'data-inspect="#egress-working"'));
        $this->assertMatchesRegularExpression('/<button[^>]*value="egress"[^>]*data-inspect="#egress-working" data-inspect-hover/s', $html);
    }

    public function test_extract_is_only_offered_on_an_extraction_run(): void
    {
        $this->machine(1);
        $this->ignoreThresholds();
        $this->instance(CheckRoller::class, CheckRoller::fromConfig(static fn (): float => 1.0));
        app(RunStarter::class)->start($this->character->fresh(), RunPurpose::Burn);

        $this->runScreen()->assertDontSeeText('Extract')->assertSeeText('Burn');
    }

    public function test_the_run_log_tags_the_turn_and_the_ice_and_speaks_plainly(): void
    {
        $this->machine(1);
        $this->ignoreThresholds();
        $run = $this->begin();

        // No icebreaker running: a cut finds nothing. Then a turn the ICE swings in (a pinned die lands it).
        $this->simulator(1)->take($this->character->fresh(), $run, RunAction::Attack);

        $this->runScreen()
            ->assertSeeText('Run log')
            ->assertDontSeeText('What happened')
            ->assertSeeText('Your intrusion attempt was detected, and the ICE activates.')
            ->assertSeeText('You find no viable attack vectors.')
            ->assertSeeText('lands a code payload for')
            ->assertSeeText(' SI.')
            ->assertSee('<span class="tag">Turn 1</span>', false)
            ->assertSee('<span class="tag tag--crit">ICE I</span>', false)
            ->assertDontSeeText('It gets through')
            ->assertDontSeeText('Something detects you');
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
        $this->everyCheckPasses();

        $result = $this->simulator(101)->take($this->character->fresh(), $run->fresh(), RunAction::Attack);

        $this->assertSame(RunEnding::Egress, $result->ending, 'it left, though Cut was asked for');
        $this->assertNull($this->character->fresh()->current_run_id);
    }

    /**
     * AND A FORCED EGRESS CAN BE HELD, which is the honest consequence of
     * leaving being a roll: a threshold is an instruction to TRY to leave, not
     * a door that opens. CYBERSPACE.md says as much — retreat can fail and
     * cost a turn — so the run carries on and the mark fires again next turn.
     */
    public function test_a_forced_egress_can_fail(): void
    {
        $this->machine(1);
        $run = $this->begin();
        $run->forceFill(['trace' => 72])->save();
        $this->everyCheckFails();

        $result = $this->simulator(101)->take($this->character->fresh(), $run->fresh(), RunAction::Attack);

        $this->assertNull($result->ending, 'held, and still in it');
        $this->assertNotNull($this->character->fresh()->current_run_id);
    }

    public function test_the_synaptic_threshold_pulls_you_out(): void
    {
        $this->machine(1);
        $run = $this->begin();

        $max = $this->character->maximumFor('current_synaptic_integrity');
        $this->character->forceFill(['current_synaptic_integrity' => (int) floor($max * 0.2)])->save();
        $this->everyCheckPasses();

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

    /**
     * Heat is persistent and belongs to the person: two per grade of the
     * system. Charged as the run ENDS, not as the trace lands — a burn later
     * in the same run would replace it.
     */
    public function test_a_completed_trace_puts_heat_on_the_character_as_the_run_ends(): void
    {
        $this->machine(1)->forceFill(['grade' => 4])->save();
        $this->ignoreThresholds();
        $run = $this->begin();
        $run->forceFill(['trace' => 98])->save();

        $before = (float) $this->character->fresh()->heat;

        $this->simulator(101)->take($this->character->fresh(), $run->fresh(), RunAction::Hold);

        $this->assertSame(100, (int) $run->fresh()->trace, 'it reached them');
        $this->assertSame($before, (float) $this->character->fresh()->heat, 'nothing yet');

        app(RunStarter::class)->end($this->character->fresh(), $run->fresh(), RunEnding::Egress);

        $this->assertSame($before + 8, (float) $this->character->fresh()->heat);
    }

    /** The entity that owns the tracing system thinks less of you for it, once, by 0.05 a grade. */
    public function test_a_completed_trace_costs_standing_with_the_owner(): void
    {
        $owner = Entity::query()->interactable()->firstOrFail();
        $this->machine(1)->forceFill(['owner_id' => $owner->id, 'grade' => 4])->save();
        $this->ignoreThresholds();
        $run = $this->begin();
        $run->forceFill(['trace' => 98])->save();

        $this->simulator(101)->take($this->character->fresh(), $run->fresh(), RunAction::Hold);
        app(RunStarter::class)->end($this->character->fresh(), $run->fresh(), RunEnding::Egress);

        // Counted by reason: being noticed on the way in costs its own entry.
        $traced = EntityLedgerEntry::query()
            ->where('character_id', $this->character->id)
            ->where('entity_id', $owner->id)
            ->where('reason', StandingReason::Traced)
            ->get();

        $this->assertCount(1, $traced);
        $this->assertEqualsWithDelta(-0.2, $traced->sole()->delta, 0.0001);
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

        app(RunStarter::class)->end($this->character->fresh(), $run->fresh(), RunEnding::Egress);
        app(RunStarter::class)->end($this->character->fresh(), $run->fresh(), RunEnding::Egress);

        $this->assertSame(2.0, (float) $this->character->fresh()->heat, 'grade 1, once');
    }

    /** A trace that never finished costs nothing. */
    public function test_an_unfinished_trace_costs_no_heat(): void
    {
        $this->machine(1);
        $this->ignoreThresholds();
        $run = $this->begin();
        $run->forceFill(['trace' => 60])->save();

        app(RunStarter::class)->end($this->character->fresh(), $run->fresh(), RunEnding::Egress);

        $this->assertSame(0.0, (float) $this->character->fresh()->heat);
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

        // A grade 9 hop lets it through 20% of the time; a grade 1 lets it
        // through 95, which is the number that keeps a worthless machine worth
        // backdooring — at 100 it could never fail and so could never delay
        // anything.
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

        // EVEN A GRADE 1 CAN HOLD IT. A roll of 100 is outside the 95 that
        // passes, so the cheapest hop in the game buys a turn here.
        $this->simulator(101)->take($this->character->fresh(), $chained, RunAction::Hold);
        $this->assertSame(1, $chained->trace_hop, 'still at the second hop');
        $this->assertSame(4, $chained->trace, 'and the trace did not move');

        // Through it on an ordinary roll — and the grade 9 behind it is never
        // asked again.
        $this->simulator(1)->take($this->character->fresh(), $chained, RunAction::Hold);
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
        $run = $this->beginUnnoticed();

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
     * what gets caught — and the ICE it wakes stands between them and the
     * core, so nothing is taken.
     */
    public function test_extraction_can_be_the_thing_that_wakes_the_ice(): void
    {
        $system = $this->machine(1);
        $system->forceFill(['ids_detection' => 50])->save();
        SystemContent::query()->create(['system_id' => $system->id, 'kind' => 'creds', 'amount' => 10]);
        $this->ignoreThresholds();

        $run = $this->beginUnnoticed();
        $this->assertFalse($run->detected);

        // Rebound to always FAIL evasion, so this turn's core touch is caught.
        $this->everyCheckFails();
        $result = $this->simulator(101)->take($this->character->fresh(), $run->fresh(), RunAction::Extract);

        $this->assertTrue($result->run->detected, 'caught on the core touch, not on approach');
        $this->assertSame($system->ice_id, $result->run->ice_id);
        $this->assertSame(0, $this->character->fresh()->creds, 'the ICE woke in time to stop it');
        $this->assertContains(Text::get('cyberspace.run.log.ice_guards'), array_column($result->log, 'text'));
    }

    /** AWAKE ICE GUARDS THE CORE: break it first, and then the take lands. */
    public function test_awake_ice_must_be_broken_before_anything_is_taken(): void
    {
        $system = $this->machine(1);
        SystemContent::query()->create(['system_id' => $system->id, 'kind' => 'creds', 'amount' => 40]);
        $this->ignoreThresholds();
        $this->runIcebreaker(12);   // 20 a cut against ICE I's 30
        $run = $this->begin();
        $sim = $this->simulator(101);

        $blocked = $sim->take($this->character->fresh(), $run->fresh(), RunAction::Extract);
        $this->assertSame(0, $this->character->fresh()->creds, 'blocked while the ICE is awake');
        $this->assertFalse($blocked->run->extracted);

        $sim->take($this->character->fresh(), $blocked->run->fresh(), RunAction::Attack);
        $broken = $sim->take($this->character->fresh(), $blocked->run->fresh(), RunAction::Attack);
        $this->assertNull($broken->run->ice_id, 'broken');

        $sim->take($this->character->fresh(), $broken->run->fresh(), RunAction::Extract);
        $this->assertSame(40, $this->character->fresh()->creds);
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
        $run = $this->beginUnnoticed();

        $afterExtract = $this->simulator(101)->take($this->character->fresh(), $run->fresh(), RunAction::Extract);

        // Rebound BEFORE the next simulator is built: `fromConfig()` resolves
        // the roller once, so a simulator made earlier keeps the old die.
        $this->everyCheckPasses();
        $result = $this->simulator(101)->take($this->character->fresh(), $afterExtract->run->fresh(), RunAction::Egress);

        $this->assertSame(RunEnding::Completed, $result->ending);
    }

    /** Leaving empty-handed is still a plain Egress. */
    public function test_egress_with_nothing_taken_stays_plain_egress(): void
    {
        $this->machine(1);
        $this->ignoreThresholds();
        $run = $this->begin();
        $this->everyCheckPasses();

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
    /**
     * A FILE COSTS TURNS. A pull can only begin with no ICE awake, and nothing
     * can wake one mid-pull — continuing never touches the core again — so the
     * countdown is a race against the trace rather than a fight.
     */
    public function test_a_multi_turn_transfer_counts_down_and_lands(): void
    {
        $system = $this->machine(1);
        SystemContent::query()->create([
            'system_id' => $system->id, 'kind' => 'data', 'label' => 'Ledger',
            'size_qb' => 120, 'value_sealed' => 10, 'value_broken' => 20,
        ]);
        $this->ignoreThresholds();
        $run = $this->beginUnnoticed();
        $sim = $this->simulator(101);

        $first = $sim->take($this->character->fresh(), $run->fresh(), RunAction::Extract);
        $this->assertSame(70, $first->run->extracting_qb);
        $this->assertFalse($first->run->extracted);

        $second = $sim->take($this->character->fresh(), $first->run->fresh(), RunAction::Extract);
        $this->assertSame(20, $second->run->extracting_qb);

        $third = $sim->take($this->character->fresh(), $second->run->fresh(), RunAction::Extract);
        $this->assertNull($third->run->extracting_qb, 'the countdown clears once the file lands');
        $this->assertTrue($third->run->extracted);

        $this->assertSame(
            1,
            InventoryItem::where('character_id', $this->character->id)
                ->whereHas('item', fn ($q) => $q->where('slug', 'encrypted-data-dump'))
                ->count(),
        );
    }

    /**
     * THE IDS WATCHES EVERY TURN OF A PULL. Unseen on the turn it began, caught
     * on a later one: the ICE wakes, the pull stops where it was, and nothing
     * more comes across until the ICE is broken.
     */
    public function test_the_ids_can_catch_a_pull_part_way_through(): void
    {
        $system = $this->machine(1);
        $system->forceFill(['ids_detection' => 50])->save();
        SystemContent::query()->create([
            'system_id' => $system->id, 'kind' => 'data', 'label' => 'Ledger',
            'size_qb' => 120, 'value_sealed' => 10, 'value_broken' => 20,
        ]);
        $this->ignoreThresholds();
        $run = $this->beginUnnoticed();

        $first = $this->simulator(101)->take($this->character->fresh(), $run->fresh(), RunAction::Extract);
        $this->assertSame(70, $first->run->extracting_qb);
        $this->assertFalse($first->run->detected);

        $this->everyCheckFails();
        $caught = $this->simulator(101)->take($this->character->fresh(), $first->run->fresh(), RunAction::Extract);

        $this->assertTrue($caught->run->detected);
        $this->assertSame($system->ice_id, $caught->run->ice_id, 'the ICE woke');
        $this->assertSame(70, $caught->run->extracting_qb, 'kept, not advanced');
        $this->assertContains(Text::get('cyberspace.run.log.pull_noticed_ice'), array_column($caught->log, 'text'));

        $again = $this->simulator(101)->take($this->character->fresh(), $caught->run->fresh(), RunAction::Extract);
        $this->assertSame(70, $again->run->extracting_qb, 'blocked while the ICE is awake');
    }

    /** A system with no IDS has nothing to watch a pull with. */
    public function test_a_pull_on_a_system_with_no_ids_is_never_caught(): void
    {
        $system = $this->machine(1);   // tracer and ICE, no IDS
        SystemContent::query()->create([
            'system_id' => $system->id, 'kind' => 'data', 'label' => 'Ledger',
            'size_qb' => 120, 'value_sealed' => 10, 'value_broken' => 20,
        ]);
        $this->ignoreThresholds();
        $run = $this->beginUnnoticed();

        $first = $this->simulator(101)->take($this->character->fresh(), $run->fresh(), RunAction::Extract);

        $this->everyCheckFails();
        $next = $this->simulator(101)->take($this->character->fresh(), $first->run->fresh(), RunAction::Extract);

        $this->assertFalse($next->run->detected);
        $this->assertSame(20, $next->run->extracting_qb);
    }

    /** Spending a turn on anything else pauses a pull rather than losing it. */
    public function test_holding_mid_transfer_leaves_its_progress_untouched(): void
    {
        $system = $this->machine(1);
        SystemContent::query()->create([
            'system_id' => $system->id, 'kind' => 'data', 'label' => 'Ledger',
            'size_qb' => 120, 'value_sealed' => 10, 'value_broken' => 20,
        ]);
        $this->ignoreThresholds();
        $run = $this->beginUnnoticed();
        $sim = $this->simulator(101);

        $afterBegin = $sim->take($this->character->fresh(), $run->fresh(), RunAction::Extract);
        $this->assertSame(70, $afterBegin->run->extracting_qb);

        $afterAttack = $sim->take($this->character->fresh(), $afterBegin->run->fresh(), RunAction::Hold);
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
        $run = $this->beginUnnoticed();
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
