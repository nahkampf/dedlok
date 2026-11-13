<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Checks\CheckRoller;
use App\Cyberspace\Party\Parties;
use App\Cyberspace\Party\PartyTurns;
use App\Cyberspace\RunAction;
use App\Cyberspace\RunPurpose;
use App\Cyberspace\RunRefusal;
use App\Cyberspace\RunStarter;
use App\Cyberspace\Simulator;
use App\Cyberspace\SystemType;
use App\Cyberspace\Tree\RunTree;
use App\Models\Character;
use App\Models\Ice;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Run;
use App\Models\RunParty;
use App\Models\System;
use App\Programs\ProgramKind;
use App\Programs\ProgramRunner;
use App\Stats\Resolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/** Run trees, step 2 (docs/RUN_TREE.md): position, moving, fog, the noise bar and the alert. */
final class TreeRunTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    private Character $character;

    private RunTree $tree;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 16);
        Config::set('cyberspace.coop.slots', 3);
        Config::set('features.intrusion_runs_v2', true);

        $system = System::factory()->at(8, 8)->grade(3)->type(SystemType::Compute)->withIds(40)->withTracer(20, 5)
            ->withIce(Ice::query()->where('level', 1)->sole())->create(['address' => 'DEADBEEF']);
        $this->tree = RunTree::of($system);

        $this->character = $this->runner();
    }

    private function runner(): Character
    {
        $c = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        $this->equipReadyDeck($c);
        $c->forceFill(['grid_x' => 8, 'grid_y' => 8, 'egress_trace_at' => 0, 'egress_si_at' => 0])->save();

        return $c->fresh();
    }

    private function checks(float $die): void
    {
        $this->instance(CheckRoller::class, CheckRoller::fromConfig(static fn (): float => $die));
    }

    /** Every stealth roll passes (0.0) or fails (1.0); noise amounts take the die's low (0) or high (1) end. */
    private function simulator(float $checks, int $die = 0): Simulator
    {
        $this->checks($checks);

        return Simulator::fromConfig(static fn (int $min, int $max): int => $die === 0 ? $min : $max, app(Resolver::class));
    }

    private function begin(float $checks = 0.0): Run
    {
        $this->checks($checks);

        return app(RunStarter::class)->start($this->character->fresh(), RunPurpose::Extraction)->run->fresh();
    }

    public function test_with_the_flag_off_a_run_is_the_single_room_it_always_was(): void
    {
        Config::set('features.intrusion_runs_v2', false);

        $run = $this->begin();

        $this->assertFalse($run->isTreeRun());
        $this->assertNull($run->noise);
    }

    public function test_a_tree_run_starts_on_the_entry_knowing_it_with_an_empty_bar(): void
    {
        $run = $this->begin();

        $this->assertTrue($run->isTreeRun());
        $this->assertSame($this->tree->entry, $run->node);
        $this->assertSame([$this->tree->entry], $run->knownNodes());
        $this->assertSame(0.0, (float) $run->noise);
        $this->assertFalse($run->detected);
    }

    public function test_arriving_unseen_by_both_watchers_is_an_act_of_noise_and_a_running_trace(): void
    {
        $run = $this->begin(1.0);

        $this->assertTrue($run->detected, 'the tracer found them: their trace runs');
        $this->assertGreaterThanOrEqual(6.0, (float) $run->noise);
        $this->assertLessThanOrEqual(10.0, (float) $run->noise);
        $this->assertFalse($run->alerted, 'one act is not 40 points');
        $this->assertNull($run->ice_id, 'the ICE on the core did not look');
    }

    public function test_a_move_goes_one_edge_only_and_a_refused_one_costs_nothing(): void
    {
        $run = $this->begin();
        $far = $this->tree->core;
        $next = $this->tree->neighbours($this->tree->entry)[0];

        $refused = $this->simulator(0.0)->take($this->character->fresh(), $run, RunAction::Move, node: $far);
        $this->assertSame(RunRefusal::NotANeighbour, $refused->refusal);
        $this->assertSame(0, $run->fresh()->turn);

        $this->simulator(0.0)->take($this->character->fresh(), $run->fresh(), RunAction::Move, node: $next);
        $run = $run->fresh();

        $this->assertSame($next, $run->node);
        $this->assertContains($next, $run->knownNodes());
        $this->assertSame(1, $run->turn);
        $this->assertSame(0.0, (float) $run->noise, 'a passed stealth roll is silent');
    }

    public function test_a_failed_stealth_roll_on_a_move_adds_a_move_and_a_hold_drains(): void
    {
        $run = $this->begin();
        $next = $this->tree->neighbours($this->tree->entry)[0];

        $this->simulator(1.0, 1)->take($this->character->fresh(), $run, RunAction::Move, node: $next);
        $this->assertSame(6.0, (float) $run->fresh()->noise, 'the top of the move tier');

        $this->simulator(1.0, 0)->take($this->character->fresh(), $run->fresh(), RunAction::Hold);
        $this->assertSame(4.0, (float) $run->fresh()->noise, 'the bottom of the drain');
    }

    public function test_noise_is_fractional(): void
    {
        $run = $this->begin();
        $next = $this->tree->neighbours($this->tree->entry)[0];

        $this->checks(1.0);
        Simulator::fromConfig(static fn (int $min, int $max): int => 43, app(Resolver::class))
            ->take($this->character->fresh(), $run, RunAction::Move, node: $next);

        $this->assertSame(4.3, (float) $run->fresh()->noise);
    }

    public function test_a_full_bar_is_the_alert_the_ice_wakes_and_only_the_alert_is_noticed(): void
    {
        $run = $this->begin();
        $run->forceFill(['noise' => 37.0])->save();
        $next = $this->tree->neighbours($this->tree->entry)[0];

        $this->assertFalse($run->noticed(), 'a quiet run');

        $this->simulator(1.0, 1)->take($this->character->fresh(), $run->fresh(), RunAction::Move, node: $next);
        $run = $run->fresh();

        $this->assertTrue($run->alerted);
        $this->assertTrue($run->detected, 'the alert starts the trace');
        $this->assertNotNull($run->ice_id, 'and wakes the ICE');
        $this->assertTrue($run->noticed());
    }

    public function test_a_running_trace_alone_is_not_noticed(): void
    {
        $run = $this->begin();
        $run->forceFill(['detected' => true])->save();

        $this->assertFalse($run->fresh()->noticed(), 'only the alert shuts the system and costs standing');
    }

    public function test_core_actions_away_from_the_core_and_nmap_without_an_nmapper_are_refused(): void
    {
        $run = $this->begin();

        foreach ([RunAction::Extract, RunAction::Install, RunAction::Burn, RunAction::Attack] as $action) {
            $this->assertSame(RunRefusal::NotHere, $this->simulator(0.0)->take($this->character->fresh(), $run->fresh(), $action)->refusal, $action->value);
        }

        $this->assertSame(RunRefusal::NoNmapper, $this->simulator(0.0)->take($this->character->fresh(), $run->fresh(), RunAction::Nmap)->refusal);
        $this->assertSame(0, $run->fresh()->turn);
    }

    public function test_on_the_core_a_core_touch_lets_the_sleeping_ice_look_and_a_failed_os_roll_is_loud(): void
    {
        $run = $this->begin();
        $run->forceFill(['node' => $this->tree->core, 'os_locked' => true])->save();

        $this->simulator(1.0, 0)->take($this->character->fresh(), $run->fresh(), RunAction::Extract);
        $run = $run->fresh();

        $this->assertNotNull($run->ice_id, 'the ICE looked and found them');
        $this->assertFalse($run->alerted, 'a woken ICE is not the alert');
        // The ICE guarded the core, so the OS was never rolled: an act, not a loud failure.
        $this->assertSame(6.0, (float) $run->noise);
    }

    public function test_a_failed_os_roll_at_the_core_is_loud(): void
    {
        System::query()->where('address', 'DEADBEEF')->update(['ice_id' => null]);
        $run = $this->begin();
        $run->forceFill(['node' => $this->tree->core, 'os_locked' => true])->save();

        $this->simulator(1.0, 0)->take($this->character->fresh(), $run->fresh(), RunAction::Extract);

        $this->assertTrue($run->fresh()->os_locked, 'the roll failed');
        $this->assertSame(20.0, (float) $run->fresh()->noise, 'the bottom of the loud tier');
    }

    public function test_with_no_ids_a_failed_os_roll_at_the_core_sets_off_the_alert(): void
    {
        System::query()->where('address', 'DEADBEEF')->update(['ice_id' => null, 'ids_detection' => 0]);
        $run = $this->begin();
        $run->forceFill(['node' => $this->tree->core, 'os_locked' => true])->save();

        $this->simulator(1.0, 0)->take($this->character->fresh(), $run->fresh(), RunAction::Extract);

        $this->assertTrue($run->fresh()->alerted);
    }

    /** @param  list<array<string, mixed>>  $modifiers */
    private function runNmapper(int $reach = 1, array $modifiers = []): void
    {
        $item = Item::factory()->program(ProgramKind::Utility, qflops: 0.1, memory: 0.1, storage: 0.1, background: true, modifiers: $modifiers)->create();
        $item->program->forceFill(['nmap_reach' => $reach])->save();
        $row = InventoryItem::create(['character_id' => $this->character->id, 'item_id' => $item->id, 'quantity' => 1]);
        $this->assertTrue(app(ProgramRunner::class)->start($this->character->fresh(), $row)->succeeded());
    }

    public function test_nmap_reveals_as_far_as_the_nmapper_reaches(): void
    {
        $this->runNmapper(2);
        $run = $this->begin();

        $this->simulator(0.0)->take($this->character->fresh(), $run, RunAction::Nmap);
        $run = $run->fresh();

        $this->assertEqualsCanonicalizing([$this->tree->entry, ...$this->tree->within($this->tree->entry, 2)], $run->knownNodes());
        $this->assertSame($this->tree->entry, $run->node, 'an Nmap does not move you');
        $this->assertSame(1, $run->turn);
    }

    public function test_the_default_nmap_exists_and_reaches_one_edge(): void
    {
        $nmap = Item::query()->where('slug', 'nmap')->sole();

        $this->assertSame(1, $nmap->program->nmap_reach);
        $this->assertSame(300, (int) $nmap->base_cost);
    }

    public function test_a_quieter_nmapper_adds_less_noise_on_a_failed_roll(): void
    {
        $this->runNmapper(1, [['op' => 'percentage_of_base', 'amount' => -50, 'target' => ['id' => 'nmap_noise']]]);
        $run = $this->begin();

        $this->simulator(1.0, 1)->take($this->character->fresh(), $run, RunAction::Nmap);

        $this->assertSame(5.0, (float) $run->fresh()->noise, 'half of the top of the act tier');
    }

    public function test_the_run_screen_draws_the_tree_with_a_move_to_each_neighbour_and_the_noise(): void
    {
        $run = $this->begin();
        $run->forceFill(['noise' => 12.5])->save();

        $page = $this->actingAs($this->character->fresh()->user)->get(route('run.show'));

        $page->assertOk();
        $page->assertSee('board--tree', false);
        foreach ($this->tree->neighbours($this->tree->entry) as $next) {
            $page->assertSee('name="node" value="'.$next.'"', false);
        }
        $page->assertSee('12.5');
        $page->assertDontSee('name="action" value="attack"', false);

        // A Move posted from the board is a turn.
        $next = $this->tree->neighbours($this->tree->entry)[0];
        $this->checks(0.0);
        $this->actingAs($this->character->fresh()->user)->post(route('run.act'), ['action' => 'move', 'node' => $next]);
        $this->assertSame($next, $run->fresh()->node);
    }

    public function test_a_party_shares_one_bar_and_the_alert_but_not_the_trace(): void
    {
        $this->checks(0.0);
        $owner = $this->character;
        $mate = $this->runner();
        $parties = new Parties;
        $parties->open($owner);
        $this->assertNull($parties->invite($owner, $mate->handle));
        $this->assertNull($parties->accept($mate));
        $this->assertTrue($parties->launch($owner, RunPurpose::Extraction, null, [], app(RunStarter::class))->succeeded());

        $runs = Run::query()->where('run_party_id', RunParty::query()->sole()->id)->get();
        $this->assertCount(2, $runs);
        [$a, $b] = [$runs[0], $runs[1]];
        $a->forceFill(['detected' => true])->save();

        $next = $this->tree->neighbours($this->tree->entry)[0];
        $this->simulator(1.0, 1);
        $this->app->instance(Simulator::class, Simulator::fromConfig(static fn (int $min, int $max): int => $max, app(Resolver::class)));
        $turns = app(PartyTurns::class);
        $turns->submit($a->fresh(), RunAction::Move, $next);

        // Not resolved yet: the other runner has not seen the reveal.
        $this->assertNotContains($next, $b->fresh()->knownNodes());

        $turns->submit($b->fresh(), RunAction::Hold);
        [$a, $b] = [$a->fresh(), $b->fresh()];

        $this->assertContains($next, $b->knownNodes(), 'the round resolved: the reveal is shared');
        $this->assertSame((float) $a->noise, (float) $b->noise, 'one bar');
        $this->assertTrue($a->detected);
        $this->assertFalse($b->detected, 'the trace is each runner\'s own');

        $a->forceFill(['noise' => 39.0])->save();
        $b->forceFill(['noise' => 39.0])->save();
        $turns->submit($a->fresh(), RunAction::Hold);
        $turns->submit($b->fresh(), RunAction::Move, $next);
        [$a, $b] = [$a->fresh(), $b->fresh()];

        $this->assertTrue($a->alerted && $b->alerted, 'the alert is the party\'s');
        $this->assertTrue($b->detected, 'and it starts every trace');
    }
}
