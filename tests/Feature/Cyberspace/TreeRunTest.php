<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Characters\WaitingRoom;
use App\Checks\CheckRoller;
use App\Cyberspace\BackdoorKind;
use App\Cyberspace\Backdoors;
use App\Cyberspace\Party\Parties;
use App\Cyberspace\Party\PartyTurns;
use App\Cyberspace\RunAction;
use App\Cyberspace\RunEnding;
use App\Cyberspace\RunPurpose;
use App\Cyberspace\RunRefusal;
use App\Cyberspace\RunStarter;
use App\Cyberspace\Simulator;
use App\Cyberspace\SystemType;
use App\Cyberspace\Tree\NodeKind;
use App\Cyberspace\Tree\RunTree;
use App\Cyberspace\Tree\TreeView;
use App\Models\Character;
use App\Models\Ice;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Notification;
use App\Models\Run;
use App\Models\RunParty;
use App\Models\Skill;
use App\Models\System;
use App\Models\SystemContent;
use App\Models\SystemScan;
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

    private function scanned(Character $character, int $tree): void
    {
        SystemScan::query()->create([
            'character_id' => $character->id, 'address' => 'DEADBEEF', 'grid_id' => 1, 'x' => 8, 'y' => 8,
            'grade' => 3, 'tree' => $tree, 'passes' => 1, 'scanned_at' => now(), 'expires_at' => now()->addHours(48),
        ]);
    }

    public function test_a_scan_that_read_the_layout_starts_the_run_with_the_whole_shape_unnamed(): void
    {
        $this->scanned($this->character, 1);

        $view = TreeView::for($this->begin(), $this->character->fresh(), $this->simulator(0.0), null);

        $this->assertCount(count($this->tree->nodes), $view->nodes);
        $this->assertCount(count($this->tree->edges), $view->links);
        $this->assertSame(1, collect($view->nodes)->where('known', true)->count(), 'only the entry is named');
    }

    public function test_a_scan_that_read_the_nodes_starts_the_run_knowing_every_node(): void
    {
        $this->scanned($this->character, 2);

        $run = $this->begin();

        $this->assertTrue($run->mapped);
        $this->assertEqualsCanonicalizing(array_keys($this->tree->nodes), $run->knownNodes());
    }

    public function test_in_a_party_the_best_scan_is_what_everybody_sees(): void
    {
        $this->checks(0.0);
        $mate = $this->runner();
        $this->scanned($mate, 1);
        $parties = new Parties;
        $parties->open($this->character);
        $this->assertNull($parties->invite($this->character, $mate->handle));
        $this->assertNull($parties->accept($mate));
        $this->assertTrue($parties->launch($this->character, RunPurpose::Extraction, null, [], app(RunStarter::class))->succeeded());

        $mine = Run::query()->where('character_id', $this->character->id)->sole();

        $this->assertFalse($mine->mapped, 'their own scan read nothing');
        $this->assertTrue($mine->mapped(), 'but their mate\'s read the Layout');
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

    /** The tree's first firewall, the node before it and the node it guards. @return array{0: int, 1: int, 2: int} */
    private function firewall(): array
    {
        $os = Skill::factory()->create(['slug' => 'lattice', 'title' => 'Lattice', 'category' => 'Operating Systems', 'rank' => 4]);
        System::query()->where('address', 'DEADBEEF')->update(['os_skill_id' => $os->id]);

        $wall = $this->tree->nodesOf(NodeKind::Firewall)[0];
        $before = collect($this->tree->edges)->first(fn (array $e): bool => $e[1] === $wall)[0];
        $after = collect($this->tree->edges)->first(fn (array $e): bool => $e[0] === $wall)[1];

        return [$before, $wall, $after];
    }

    public function test_a_failed_breach_holds_the_firewall_and_it_is_loud(): void
    {
        [, $wall, $after] = $this->firewall();
        $run = $this->begin();
        $run->forceFill(['node' => $wall])->save();

        $this->assertSame(RunRefusal::FirewallClosed, $this->simulator(0.0)->take($this->character->fresh(), $run->fresh(), RunAction::Move, node: $after)->refusal);

        $this->simulator(1.0, 0)->take($this->character->fresh(), $run->fresh(), RunAction::Breach);
        $run = $run->fresh();

        $this->assertSame($wall, $run->node);
        $this->assertSame(20.0, (float) $run->noise, 'the bottom of the loud tier');
        $this->assertSame([], $run->openedFirewalls());
    }

    public function test_a_breach_opens_the_firewall_for_the_party_and_moving_on_is_the_next_turn(): void
    {
        [$before, $wall, $after] = $this->firewall();
        $run = $this->begin();
        $this->assertSame(RunRefusal::NotHere, $this->simulator(0.0)->take($this->character->fresh(), $run->fresh(), RunAction::Breach)->refusal, 'only on a firewall');
        $run->forceFill(['node' => $wall])->save();

        $this->simulator(0.0)->take($this->character->fresh(), $run->fresh(), RunAction::Breach);
        $run = $run->fresh();
        $this->assertSame($wall, $run->node, 'a breach does not move');
        $this->assertSame([$wall], $run->openedFirewalls());

        // Open now: on without a roll, back the way you came, and on again.
        $this->simulator(1.0, 0)->take($this->character->fresh(), $run, RunAction::Move, node: $after);
        $this->assertSame($after, $run->fresh()->node);
        $this->simulator(1.0, 0)->take($this->character->fresh(), $run->fresh(), RunAction::Move, node: $wall);
        $this->simulator(1.0, 0)->take($this->character->fresh(), $run->fresh(), RunAction::Move, node: $before);
        $this->assertSame($before, $run->fresh()->node);
    }

    public function test_egress_is_only_from_the_entry(): void
    {
        $run = $this->begin();
        $run->forceFill(['node' => $this->tree->core])->save();

        $this->assertSame(RunRefusal::NotHere, $this->simulator(0.0)->take($this->character->fresh(), $run->fresh(), RunAction::Egress)->refusal);
    }

    public function test_the_ice_on_the_core_strikes_nobody_elsewhere_and_holds_nobody_at_the_entry(): void
    {
        $run = $this->begin();
        $ice = Ice::query()->where('level', 1)->sole();
        $run->forceFill(['ice_id' => $ice->id, 'ice_integrity' => $ice->integrity])->save();
        $si = (float) $this->character->fresh()->current_synaptic_integrity;

        $this->simulator(0.0, 1)->take($this->character->fresh(), $run->fresh(), RunAction::Hold);

        $this->assertSame($si, (float) $this->character->fresh()->current_synaptic_integrity, 'nothing reached the entry');
        $this->assertFalse($run->fresh()->iceReachesHere());
    }

    public function test_leaving_the_core_mid_fight_costs_a_parting_hit_and_the_ice_keeps_its_damage(): void
    {
        $run = $this->begin();
        $ice = Ice::query()->where('level', 1)->sole();
        $run->forceFill(['node' => $this->tree->core, 'ice_id' => $ice->id, 'ice_integrity' => 3, 'ice_damage' => 5])->save();
        $away = $this->tree->neighbours($this->tree->core)[0];
        $si = (float) $this->character->fresh()->current_synaptic_integrity;

        // The die at its low end: the ICE's skill roll lands.
        $this->simulator(0.0, 0)->take($this->character->fresh(), $run->fresh(), RunAction::Move, node: $away);
        $run = $run->fresh();

        $this->assertSame($away, $run->node);
        $this->assertLessThan($si, (float) $this->character->fresh()->current_synaptic_integrity, 'one parting hit');
        $this->assertSame(3.0, (float) $run->ice_integrity, 'as damaged as it was left');
    }

    public function test_a_threshold_only_warns_on_a_tree_run(): void
    {
        $this->character->forceFill(['egress_trace_at' => 1])->save();
        $run = $this->begin();
        $run->forceFill(['detected' => true, 'trace' => 50])->save();

        $result = $this->simulator(0.0)->take($this->character->fresh(), $run->fresh(), RunAction::Hold);

        $this->assertNull($result->ending);
        $this->assertTrue($run->fresh()->isLive(), 'nobody was pulled out');
    }

    public function test_a_backdoor_holder_starts_at_the_core_unless_they_choose_the_entry(): void
    {
        (new Backdoors)->install($this->character, System::query()->where('address', 'DEADBEEF')->sole(), BackdoorKind::Siphon);

        $this->checks(1.0);
        $run = app(RunStarter::class)->start($this->character->fresh(), RunPurpose::Extraction)->run;
        $this->assertSame($this->tree->core, $run->node);
        $this->assertSame(0.0, (float) $run->noise, 'the arrival rolls are skipped');
        app(RunStarter::class)->end($this->character->fresh(), $run, RunEnding::Egress);

        $run = app(RunStarter::class)->start($this->character->fresh(), RunPurpose::Extraction, atEntry: true)->run;
        $this->assertSame($this->tree->entry, $run->node);
        $this->assertSame(0.0, (float) $run->noise, 'still skipped at the entry');
    }

    public function test_the_briefing_offers_where_to_start_only_to_a_backdoor_holder(): void
    {
        $this->actingAs($this->character->fresh()->user)->get(route('run.setup'))->assertOk()->assertDontSee('name="start_at"', false);

        (new Backdoors)->install($this->character, System::query()->where('address', 'DEADBEEF')->sole(), BackdoorKind::Siphon);

        $this->actingAs($this->character->fresh()->user)->get(route('run.setup'))->assertOk()
            ->assertSee('name="start_at" value="core"', false)->assertSee('name="start_at" value="entry"', false);
    }

    public function test_crash_jackout_takes_a_quarter_to_a_half_of_max_si_and_leaves_them_on_the_grid(): void
    {
        $run = $this->begin();
        $run->forceFill(['node' => $this->tree->core])->save();
        $character = $this->character->fresh();
        $max = (float) $character->maximumFor('current_synaptic_integrity');
        $character->forceFill(['current_synaptic_integrity' => $max])->save();

        $result = $this->simulator(0.0, 0)->take($character->fresh(), $run->fresh(), RunAction::Jackout);
        $character = $character->fresh();

        $this->assertSame(RunEnding::Jackout, $result->ending);
        $this->assertEqualsWithDelta($max * .75, (float) $character->current_synaptic_integrity, .01, 'the low end: 25%');
        $this->assertNull($character->current_run_id);
        $this->assertTrue($character->isJackedIn(), 'on the grid, like after an egress');
    }

    public function test_crash_jackout_after_extracting_is_completed(): void
    {
        $run = $this->begin();
        $run->forceFill(['extracted' => true])->save();

        $this->assertSame(RunEnding::Completed, $this->simulator(0.0, 0)->take($this->character->fresh(), $run->fresh(), RunAction::Jackout)->ending);
    }

    public function test_a_crash_jackout_that_empties_them_is_the_waiting_screen_and_neural_damage(): void
    {
        $run = $this->begin();
        $character = $this->character->fresh();
        $max = (float) $character->maximumFor('current_synaptic_integrity');
        $character->forceFill(['current_synaptic_integrity' => 1])->save();

        $this->simulator(0.0, 1)->take($character->fresh(), $run->fresh(), RunAction::Jackout);
        $character = $character->fresh();

        $this->assertFalse($character->isJackedIn(), 'off the grid before the waiting screen');
        $this->assertTrue(app(WaitingRoom::class)->isDown($character));
        $this->assertLessThan($max, (float) $character->maximumFor('current_synaptic_integrity'), 'neural damage lowered the maximum');
    }

    public function test_only_a_tree_run_can_crash_jackout(): void
    {
        Config::set('features.intrusion_runs_v2', false);
        $run = $this->begin();

        $this->assertSame(RunRefusal::NotHere, $this->simulator(0.0)->take($this->character->fresh(), $run, RunAction::Jackout)->refusal);
    }

    public function test_the_run_screen_offers_crash_jackout_behind_a_confirmation(): void
    {
        $this->begin();

        $this->actingAs($this->character->fresh()->user)->get(route('run.show'))->assertOk()
            ->assertSee('data-open="#jackout-confirm"', false)
            ->assertSee('<dialog id="jackout-confirm"', false)
            ->assertSee('name="action" value="jackout"', false);
    }

    public function test_an_inactive_solo_runner_is_jacked_out_after_ten_minutes_with_no_damage(): void
    {
        $run = $this->begin();
        $si = (float) $this->character->fresh()->current_synaptic_integrity;

        $run->forceFill(['acted_at' => now()->subMinutes(9)])->save();
        $this->artisan('cyberspace:idle-runs')->assertSuccessful();
        $this->assertTrue($run->fresh()->isLive(), 'nine minutes is not ten');

        $run->forceFill(['acted_at' => now()->subMinutes(11)])->save();
        $this->artisan('cyberspace:idle-runs')->assertSuccessful();

        $this->assertSame('jackout', $run->fresh()->ending);
        $this->assertSame($si, (float) $this->character->fresh()->current_synaptic_integrity);
        $this->assertSame(1, Notification::query()->where('user_id', $this->character->user_id)->count());
    }

    /** The system as a silo, which always keeps a vault from grade 2; the tree that gives it. */
    private function asSilo(): RunTree
    {
        System::query()->where('address', 'DEADBEEF')->update(['type' => SystemType::Silo->value]);

        return $this->tree = RunTree::of(System::query()->where('address', 'DEADBEEF')->sole());
    }

    private function loot(array $row): SystemContent
    {
        return SystemContent::query()->create($row + ['system_id' => System::query()->where('address', 'DEADBEEF')->value('id')]);
    }

    public function test_the_restock_fills_each_vault_while_the_flag_is_on_and_vault_loot_never_blocks_the_core(): void
    {
        $tree = $this->asSilo();
        $vaults = $tree->nodesOf(NodeKind::Vault);
        $this->assertNotEmpty($vaults);

        $this->artisan('cyberspace:restock')->assertSuccessful();
        $rows = SystemContent::query()->get();

        $this->assertNotEmpty($rows->whereNull('node'), 'the core');
        $this->assertEqualsCanonicalizing($vaults, $rows->whereNotNull('node')->pluck('node')->unique()->values()->all(), 'every vault');

        // The core emptied, the vaults full: the core still restocks.
        SystemContent::query()->whereNull('node')->delete();
        $this->artisan('cyberspace:restock')->assertSuccessful();
        $this->assertNotEmpty(SystemContent::query()->whereNull('node')->get());
    }

    public function test_with_the_flag_off_no_vault_is_stocked(): void
    {
        $this->asSilo();
        Config::set('features.intrusion_runs_v2', false);

        $this->artisan('cyberspace:restock')->assertSuccessful();

        $this->assertSame(0, SystemContent::query()->whereNotNull('node')->count());
    }

    public function test_a_vault_pulls_on_any_purpose_with_no_lock_and_is_no_raid(): void
    {
        $tree = $this->asSilo();
        $vault = $tree->nodesOf(NodeKind::Vault)[0];
        $this->loot(['node' => $vault, 'kind' => 'creds', 'amount' => 300]);
        $this->loot(['kind' => 'creds', 'amount' => 999]);
        $this->checks(0.0);
        $run = app(RunStarter::class)->start($this->character->fresh(), RunPurpose::Burn)->run;
        $run->forceFill(['node' => $vault, 'os_locked' => true])->save();
        $creds = (int) $this->character->fresh()->creds;

        $this->simulator(0.0)->take($this->character->fresh(), $run->fresh(), RunAction::Extract);
        $run = $run->fresh();

        $this->assertSame($creds + 300, (int) $this->character->fresh()->creds, 'the vault\'s creds, not the core\'s');
        $this->assertFalse($run->extracted, 'a side haul does not finish the run');
        $this->assertTrue($run->os_locked, 'no OS was asked');
        $this->assertSame(0, (int) System::query()->where('address', 'DEADBEEF')->value('raids'));
        $this->assertSame(999, (int) SystemContent::query()->whereNull('node')->value('amount'), 'the core keeps its own');
    }

    public function test_extracting_at_another_node_drops_the_paused_pull(): void
    {
        $tree = $this->asSilo();
        $vault = $tree->nodesOf(NodeKind::Vault)[0];
        $run = $this->begin();
        $run->forceFill(['node' => $tree->core, 'os_locked' => false, 'extracting_qb' => 40, 'pull_node' => $vault])->save();

        $this->simulator(0.0)->take($this->character->fresh(), $run->fresh(), RunAction::Extract);
        $run = $run->fresh();

        $this->assertNull($run->pull_node, 'whatever runs now is the core\'s');
        $this->assertNotEquals(40.0, (float) $run->extracting_qb);
    }

    public function test_the_run_screen_offers_extract_on_a_vault(): void
    {
        $tree = $this->asSilo();
        $run = $this->begin();
        $run->forceFill(['node' => $tree->nodesOf(NodeKind::Vault)[0]])->save();

        $this->actingAs($this->character->fresh()->user)->get(route('run.show'))->assertOk()
            ->assertSee('name="action" value="extract"', false);
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

        // Left alone: five minutes from the moment the other left, not from the last turn.
        app(RunStarter::class)->end(Character::query()->find($a->character_id), $a->fresh(), RunEnding::Egress);
        $b->forceFill(['acted_at' => now()->subHour()])->save();
        $this->artisan('cyberspace:idle-runs')->assertSuccessful();
        $this->assertTrue($b->fresh()->isLive(), 'just left alone');

        $this->travel(6)->minutes();
        $this->artisan('cyberspace:idle-runs')->assertSuccessful();
        $this->assertSame('jackout', $b->fresh()->ending);
    }
}
