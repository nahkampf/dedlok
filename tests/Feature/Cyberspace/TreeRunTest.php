<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Characters\WaitingRoom;
use App\Checks\CheckRoller;
use App\Cyberspace\BackdoorKind;
use App\Cyberspace\Backdoors;
use App\Cyberspace\Party\Parties;
use App\Cyberspace\Party\PartyRefusal;
use App\Cyberspace\Party\PartyTurns;
use App\Cyberspace\RunAction;
use App\Cyberspace\RunEnding;
use App\Cyberspace\RunPurpose;
use App\Cyberspace\RunRefusal;
use App\Cyberspace\RunStarter;
use App\Cyberspace\Simulator;
use App\Cyberspace\SystemType;
use App\Cyberspace\Tree\Explored;
use App\Cyberspace\Tree\NodeKind;
use App\Cyberspace\Tree\RunTree;
use App\Cyberspace\Tree\TrapEffect;
use App\Cyberspace\Tree\TreeView;
use App\Missions\MissionAction;
use App\Missions\MissionState;
use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\Entity;
use App\Models\EntityBook;
use App\Models\Exchange;
use App\Models\Ice;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Leech;
use App\Models\Mission;
use App\Models\Notification;
use App\Models\Program;
use App\Models\Run;
use App\Models\RunParty;
use App\Models\Skill;
use App\Models\System;
use App\Models\SystemContent;
use App\Models\SystemScan;
use App\Programs\ProgramKind;
use App\Programs\ProgramRunner;
use App\Stats\Resolver;
use App\Support\Text;
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

    public function test_a_leecher_is_hung_on_a_tree_s_core_and_nowhere_else(): void
    {
        $fortress = System::factory()->at(30, 30)->create(['type' => SystemType::Fortress, 'owner_character_id' => $this->character->id]);
        $item = Item::query()->create(['slug' => 'storage-leecher', 'name' => 'Storage Leecher', 'container' => 'data', 'stackable' => true]);
        Program::query()->create(['item_id' => $item->id, 'kind' => ProgramKind::Leecher, 'storage_size' => 0.4, 'leech_resource' => 'storage', 'leech_amount' => 4.5, 'qflops_cost' => 0, 'memory_cost' => 0, 'background_process' => false]);
        InventoryItem::query()->create(['character_id' => $this->character->id, 'item_id' => $item->id, 'quantity' => 1]);
        $run = $this->begin();

        $this->assertSame(RunRefusal::NotHere, $this->simulator(0.0)->take($this->character->fresh(), $run->fresh(), RunAction::Leech)->refusal, 'not off the core');
        $this->actingAs($this->character->fresh()->user)->get(route('run.show'))->assertOk()->assertDontSee('value="leech"', false);

        $run->fresh()->forceFill(['node' => $this->tree->core, 'os_locked' => false])->save();
        $this->actingAs($this->character->fresh()->user)->get(route('run.show'))->assertOk()->assertSee('value="leech"', false);
        $this->assertNull($this->simulator(0.0)->take($this->character->fresh(), $run->fresh(), RunAction::Leech, fortressId: $fortress->id)->refusal);

        $this->assertTrue(Leech::query()->where('character_id', $this->character->id)->where('host_system_id', System::query()->where('address', 'DEADBEEF')->value('id'))->exists(), 'hung on the core');
        $this->assertSame(0, (int) InventoryItem::query()->where('character_id', $this->character->id)->where('item_id', $item->id)->sum('quantity'), 'the Leecher is spent');
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
    private function runNmapper(int $reach = 1, array $modifiers = [], bool $deep = false): void
    {
        $item = Item::factory()->program(ProgramKind::Utility, qflops: 0.1, memory: 0.1, storage: 0.1, background: true, modifiers: $modifiers)->create();
        $item->program->forceFill(['nmap_reach' => $reach, 'nmap_deep' => $deep])->save();
        $row = InventoryItem::create(['character_id' => $this->character->id, 'item_id' => $item->id, 'quantity' => 1]);
        $this->assertTrue(app(ProgramRunner::class)->start($this->character->fresh(), $row)->succeeded());
    }

    public function test_nmap_reveals_as_far_as_the_nmapper_reaches(): void
    {
        $this->runNmapper(2);
        $run = $this->begin();

        // The nearest ring first: one edge out.
        $this->simulator(0.0)->take($this->character->fresh(), $run, RunAction::Nmap);
        $run = $run->fresh();
        $this->assertEqualsCanonicalizing([$this->tree->entry, ...$this->tree->within($this->tree->entry, 1)], $run->knownNodes());
        $this->assertSame($this->tree->entry, $run->node, 'an Nmap does not move you');
        $this->assertSame(1, $run->turn);

        // Then the next, as far as the Nmapper reaches.
        $this->simulator(0.0)->take($this->character->fresh(), $run, RunAction::Nmap);
        $this->assertEqualsCanonicalizing([$this->tree->entry, ...$this->tree->within($this->tree->entry, 2)], $run->fresh()->knownNodes());

        // Nothing left in reach: refused, and greyed out on the screen.
        $this->assertSame(RunRefusal::NothingToMap, $this->simulator(0.0)->take($this->character->fresh(), $run->fresh(), RunAction::Nmap)->refusal);
        $this->assertFalse(TreeView::for($run->fresh(), $this->character->fresh(), app(Simulator::class), null)->canNmap);
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

    /** A grade 7 tree (quiet and rich always meet at a hub) with an OS; the runner stands on the core beside the hub. */
    private function hub(): array
    {
        $os = Skill::factory()->create(['slug' => 'lattice', 'title' => 'Lattice', 'category' => 'Operating Systems', 'rank' => 4]);
        System::query()->where('address', 'DEADBEEF')->update(['os_skill_id' => $os->id, 'grade' => 7]);
        $tree = RunTree::of(System::query()->where('address', 'DEADBEEF')->sole());
        $run = $this->begin();
        $run->forceFill(['node' => $tree->core])->save();

        return [$run->fresh(), $tree->nodesOf(NodeKind::Hub)[0]];
    }

    public function test_stepping_onto_a_security_hub_is_loud_and_a_failed_subvert_too(): void
    {
        [$run, $hub] = $this->hub();
        $this->assertSame(RunRefusal::NotHere, $this->simulator(0.0)->take($this->character->fresh(), $run, RunAction::Subvert)->refusal, 'only on the hub');

        $this->simulator(1.0, 0)->take($this->character->fresh(), $run->fresh(), RunAction::Move, node: $hub);
        $this->assertSame(20.0, (float) $run->fresh()->noise, 'the bottom of the loud tier');

        $this->simulator(1.0, 0)->take($this->character->fresh(), $run->fresh(), RunAction::Subvert);
        $this->assertSame(40.0, (float) $run->fresh()->noise);
        $this->assertTrue($run->fresh()->alerted, 'the bar is full');
    }

    public function test_a_subverted_hub_lets_the_bar_fill_and_nothing_go_off(): void
    {
        [$run, $hub] = $this->hub();
        $run->forceFill(['node' => $hub])->save();

        $this->simulator(0.0)->take($this->character->fresh(), $run->fresh(), RunAction::Subvert);
        $this->assertSame([$hub], $run->fresh()->openedFirewalls());
        $this->assertSame(RunRefusal::NotHere, $this->simulator(0.0)->take($this->character->fresh(), $run->fresh(), RunAction::Subvert)->refusal, 'once is enough');

        $run->fresh()->forceFill(['noise' => 39.0])->save();
        $core = RunTree::of(System::query()->where('address', 'DEADBEEF')->sole())->core;
        $this->simulator(1.0, 0)->take($this->character->fresh(), $run->fresh(), RunAction::Move, node: $core);
        $run = $run->fresh();
        $this->assertSame($core, $run->node);
        $this->assertGreaterThanOrEqual(40.0, (float) $run->noise, 'the bar still fills');
        $this->assertFalse($run->alerted);
    }

    public function test_with_no_ids_the_hub_is_a_conduit(): void
    {
        System::query()->where('address', 'DEADBEEF')->update(['grade' => 7, 'ids_detection' => 0]);
        $system = System::query()->where('address', 'DEADBEEF')->sole();

        $this->assertSame([], RunTree::of($system)->nodesOf(NodeKind::Hub));
        $this->assertCount(1, RunTree::generate('DEADBEEF', 7, SystemType::Compute)->nodesOf(NodeKind::Hub));
    }

    /**
     * A grade 3 relay system (its quiet path always carries an internal relay), the runner standing on the relay with
     * their trace running and two Loopback scripts in the stash.
     *
     * @return array{0: Run, 1: int}
     */
    private function relay(): array
    {
        System::query()->where('address', 'DEADBEEF')->update(['type' => SystemType::Relay->value]);
        $tree = RunTree::of(System::query()->where('address', 'DEADBEEF')->sole());
        $item = Item::factory()->program(ProgramKind::Script, qflops: 0.1, memory: 0.1, storage: 0.1, background: false, lifetime: 3600)->create();
        $item->program->forceFill(['loopback_strength' => 10])->save();
        InventoryItem::create(['character_id' => $this->character->id, 'item_id' => $item->id, 'quantity' => 2]);

        $run = $this->begin();
        $relay = $tree->nodesOf(NodeKind::Relay)[0];
        $run->forceFill(['node' => $relay, 'detected' => true, 'trace' => 0])->save();

        return [$run->fresh(), $relay];
    }

    private function loopbacks(): int
    {
        return (int) InventoryItem::query()->where('character_id', $this->character->id)
            ->whereHas('item.program', static fn ($q) => $q->whereNotNull('loopback_strength'))->sum('quantity');
    }

    public function test_a_loopback_on_a_relay_stalls_the_trace_until_a_hold_roll_fails(): void
    {
        [$run] = $this->relay();

        $this->simulator(0.0)->take($this->character->fresh(), $run, RunAction::Loopback);
        $run = $run->fresh();
        $this->assertNotNull($run->loopback);
        $this->assertTrue(InventoryItem::query()->find($run->loopback)->isRunning(), 'it runs on the deck');
        $this->assertSame(0, (int) $run->trace, 'stalled on the turn it starts');
        $this->assertSame(RunRefusal::NotHere, $this->simulator(0.0)->take($this->character->fresh(), $run, RunAction::Loopback)->refusal, 'one at a time');

        $this->simulator(0.0)->take($this->character->fresh(), $run, RunAction::Hold);
        $this->assertSame(0, (int) $run->fresh()->trace, 'held');

        $this->simulator(1.0)->take($this->character->fresh(), $run->fresh(), RunAction::Hold);
        $run = $run->fresh();
        $this->assertNull($run->loopback);
        $this->assertGreaterThan(0, (int) $run->trace, 'the trace moves on the turn it fails');
        $this->assertSame(1, $this->loopbacks(), 'the failed copy is spent');
    }

    public function test_leaving_the_relay_or_the_run_spends_the_loopback(): void
    {
        [$run, $relay] = $this->relay();
        $tree = RunTree::of(System::query()->where('address', 'DEADBEEF')->sole());

        $this->simulator(0.0)->take($this->character->fresh(), $run, RunAction::Loopback);
        $this->simulator(0.0)->take($this->character->fresh(), $run->fresh(), RunAction::Move, node: $tree->neighbours($relay)[0]);
        $this->assertNull($run->fresh()->loopback);
        $this->assertSame(1, $this->loopbacks());

        $run->fresh()->forceFill(['node' => $relay])->save();
        $this->simulator(0.0)->take($this->character->fresh(), $run->fresh(), RunAction::Loopback);
        app(RunStarter::class)->end($this->character->fresh(), $run->fresh(), RunEnding::Egress);
        $this->assertSame(0, $this->loopbacks());
    }

    public function test_a_loopback_needs_a_relay_and_a_script(): void
    {
        $run = $this->begin();
        $this->assertSame(RunRefusal::NotHere, $this->simulator(0.0)->take($this->character->fresh(), $run, RunAction::Loopback)->refusal);

        app(RunStarter::class)->end($this->character->fresh(), $run->fresh(), RunEnding::Egress);
        [$run] = $this->relay();
        InventoryItem::query()->where('character_id', $this->character->id)->whereHas('item.program', static fn ($q) => $q->whereNotNull('loopback_strength'))->delete();
        $this->assertSame(RunRefusal::NoLoopback, $this->simulator(0.0)->take($this->character->fresh(), $run, RunAction::Loopback)->refusal);
    }

    /**
     * A grade 7 compute tree with a trap of this effect, an OS, and the runner one edge from it (every firewall open).
     *
     * @return array{0: Run, 1: int, 2: int} The run, the trap, and where the runner stands.
     */
    private function trap(TrapEffect $effect): array
    {
        for ($i = 0; ; $i++) {
            $tree = RunTree::generate($address = sprintf('%08X', crc32("trap-{$i}")), 7, SystemType::Compute);
            $found = array_values(array_filter($tree->nodesOf(NodeKind::Trap), static fn (int $n): bool => $tree->nodes[$n]['trap'] === $effect));

            if ($found !== []) {
                break;
            }
        }

        $os = Skill::factory()->create(['slug' => 'lattice', 'title' => 'Lattice', 'category' => 'Operating Systems', 'rank' => 4]);
        System::query()->where('address', 'DEADBEEF')->update(['address' => $address, 'grade' => 7, 'os_skill_id' => $os->id]);
        $tree = RunTree::of(System::query()->where('address', $address)->sole());
        $trap = $found[0];
        $from = $tree->neighbours($trap)[0];

        $run = $this->begin();
        $run->forceFill(['node' => $from, 'opened' => $tree->nodesOf(NodeKind::Firewall), 'detected' => true, 'trace' => 0])->save();

        return [$run->fresh(), $trap, $from];
    }

    public function test_a_trap_reads_as_a_conduit_until_found(): void
    {
        [$run, $trap] = $this->trap(TrapEffect::Alarm);
        $run->forceFill(['known' => [...$run->knownNodes(), $trap]])->save();
        $label = static fn (Run $r): string => collect(TreeView::for($r, $r->character, app(Simulator::class), null)->nodes)->firstWhere('key', $trap)['label'];

        $this->assertSame(Text::get('cyberspace.tree.node.conduit'), $label($run->fresh()));

        $run->fresh()->forceFill(['traps' => [$trap => 'found']])->save();
        $this->assertSame(Text::get('cyberspace.tree.trap_label', ['effect' => TrapEffect::Alarm->label()]), $label($run->fresh()));
    }

    public function test_an_alarm_trap_is_loud_without_a_roll_and_then_sprung(): void
    {
        [$run, $trap] = $this->trap(TrapEffect::Alarm);

        $this->simulator(0.0, 0)->take($this->character->fresh(), $run, RunAction::Move, node: $trap);
        $run = $run->fresh();

        $this->assertSame($trap, $run->node);
        $this->assertSame(20.0, (float) $run->noise, 'the loud tier, with every stealth roll passing');
        $this->assertSame('sprung', $run->trapStates()[$trap]);
        $this->assertNull(app(Simulator::class)->liveTrap($run, $trap));
    }

    public function test_a_trace_trap_jumps_the_trace_two_turns_from_grade_7(): void
    {
        [$run, $trap] = $this->trap(TrapEffect::Trace);
        $rate = (int) System::query()->where('address', $run->address)->value('tracer_rate');

        $this->simulator(0.0)->take($this->character->fresh(), $run, RunAction::Move, node: $trap);

        // Two turns of the jump, then the turn's own advance.
        $this->assertSame(min(100, 3 * $rate), (int) $run->fresh()->trace);
    }

    public function test_an_ensnare_trap_holds_the_runner_up_to_four_turns_from_grade_7_and_logs_the_turns_left(): void
    {
        [$run, $trap, $from] = $this->trap(TrapEffect::Ensnare);

        // The die at its top: the grade's most, four turns.
        $this->simulator(0.0, 1)->take($this->character->fresh(), $run, RunAction::Move, node: $trap);
        $this->assertSame('cyberspace.run.log.tree.trap.held', collect($run->fresh()->log)->last()['key']);
        $this->assertSame(4, collect($run->fresh()->log)->last()['replace']['turns']);

        foreach ([3, 2, 1, 0] as $left) {
            $this->assertSame(RunRefusal::Ensnared, $this->simulator(0.0)->take($this->character->fresh(), $run->fresh(), RunAction::Move, node: $from)->refusal);
            $this->simulator(0.0)->take($this->character->fresh(), $run->fresh(), RunAction::Hold);
            $held = collect($run->fresh()->log)->where('turn', $run->fresh()->turn)->firstWhere('key', 'cyberspace.run.log.tree.trap.held');
            $this->assertSame($left ?: null, $held['replace']['turns'] ?? null);
        }

        $this->assertNull($this->simulator(0.0)->take($this->character->fresh(), $run->fresh(), RunAction::Move, node: $from)->refusal);
    }

    public function test_a_node_disconnect_tears_the_node_out_and_the_runner_stays(): void
    {
        [$run, $trap, $from] = $this->trap(TrapEffect::Disconnect);

        $this->simulator(0.0)->take($this->character->fresh(), $run, RunAction::Move, node: $trap);
        $run = $run->fresh();

        $this->assertSame($from, $run->node);
        $this->assertSame([$trap], $run->cutNodes());
        $this->assertSame(RunRefusal::NotANeighbour, $this->simulator(0.0)->take($this->character->fresh(), $run, RunAction::Move, node: $trap)->refusal);
        $this->assertNotContains($trap, array_column(TreeView::for($run->fresh(), $this->character->fresh(), app(Simulator::class), null)->nodes, 'key'));
    }

    public function test_a_found_trap_disarms_from_next_door_and_a_failed_disarm_sets_it_off(): void
    {
        [$run, $trap] = $this->trap(TrapEffect::Alarm);
        $this->assertSame(RunRefusal::NotHere, $this->simulator(0.0)->take($this->character->fresh(), $run, RunAction::Disarm, node: $trap)->refusal, 'not found yet');

        $run->forceFill(['traps' => [$trap => 'found']])->save();
        $this->assertCount(1, TreeView::for($run->fresh(), $this->character->fresh(), app(Simulator::class), null)->disarm);
        $this->simulator(1.0, 0)->take($this->character->fresh(), $run->fresh(), RunAction::Disarm, node: $trap);
        $run = $run->fresh();
        $this->assertSame('sprung', $run->trapStates()[$trap], 'it went off');
        $this->assertSame(40.0, (float) $run->noise, 'the alarm and the loud failure');

        [$run, $trap] = [$run, $trap];
        $run->forceFill(['traps' => [$trap => 'found'], 'noise' => 0])->save();
        $this->simulator(0.0)->take($this->character->fresh(), $run->fresh(), RunAction::Disarm, node: $trap);
        $this->assertSame('spent', $run->fresh()->trapStates()[$trap]);
        $this->assertSame(0.0, (float) $run->fresh()->noise);
    }

    public function test_a_scan_that_read_the_traps_starts_the_run_knowing_them(): void
    {
        [$run] = $this->trap(TrapEffect::Alarm);
        $address = $run->address;
        app(RunStarter::class)->end($this->character->fresh(), $run, RunEnding::Egress);
        $this->scanned($this->character, 3);
        SystemScan::query()->where('character_id', $this->character->id)->update(['address' => $address]);

        $run = $this->begin();
        $tree = RunTree::of(System::query()->where('address', $address)->sole());
        $this->assertSame(array_fill_keys($tree->nodesOf(NodeKind::Trap), 'found'), $run->trapStates());
    }

    public function test_a_honeypot_reads_as_a_vault_holds_nothing_and_goes_off_on_the_first_extract(): void
    {
        for ($i = 0; ; $i++) {
            $tree = RunTree::generate($address = sprintf('%08X', crc32("honey-{$i}")), 7, SystemType::Silo);
            $pots = array_values(array_filter($tree->nodesOf(NodeKind::Vault), static fn (int $n): bool => ($tree->nodes[$n]['trap'] ?? null) === TrapEffect::Alarm));

            if ($pots !== []) {
                break;
            }
        }

        System::query()->where('address', 'DEADBEEF')->update(['address' => $address, 'grade' => 7, 'type' => SystemType::Silo->value]);
        $run = $this->begin();
        $run->forceFill(['node' => $pots[0], 'known' => [...$run->knownNodes(), $pots[0]]])->save();

        $view = TreeView::for($run->fresh(), $this->character->fresh(), app(Simulator::class), null);
        $this->assertSame(Text::get('cyberspace.tree.node.vault'), collect($view->nodes)->firstWhere('key', $pots[0])['label']);
        $this->assertTrue($view->atVault);

        $this->simulator(0.0, 0)->take($this->character->fresh(), $run->fresh(), RunAction::Extract);
        $run = $run->fresh();
        $this->assertSame(20.0, (float) $run->noise, 'the alarm, every stealth roll passing');
        $this->assertSame('sprung', $run->trapStates()[$pots[0]]);
        $this->assertNull($run->extracting_qb, 'there was nothing to pull');
    }

    public function test_wiping_the_logs_after_the_alert_gives_the_standing_back_and_spares_the_lockdown(): void
    {
        $entity = Entity::query()->interactable()->firstOrFail();
        $os = Skill::factory()->create(['slug' => 'lattice', 'title' => 'Lattice', 'category' => 'Operating Systems', 'rank' => 4]);
        System::query()->where('address', 'DEADBEEF')->update(['grade' => 7, 'os_skill_id' => $os->id, 'owner_id' => $entity->id]);
        $tree = RunTree::of(System::query()->where('address', 'DEADBEEF')->sole());
        $log = $tree->nodesOf(NodeKind::Log)[0];
        $from = $tree->neighbours($log)[0];
        $book = fn (): float => (float) EntityBook::query()->where('character_id', $this->character->id)->where('entity_id', $entity->id)->value('standing');

        $run = $this->begin();
        $run->forceFill(['node' => $log])->save();
        $this->assertSame(RunRefusal::NotHere, $this->simulator(0.0)->take($this->character->fresh(), $run->fresh(), RunAction::Wipe)->refusal, 'nothing to wipe before the alert');
        $quiet = TreeView::for($run->fresh(), $this->character->fresh(), app(Simulator::class), null);
        $this->assertTrue($quiet->logQuiet, 'Wipe shows greyed out before the alert');
        $this->assertNull($quiet->wipeChance);

        // The alert, on the step back off the log server.
        $run->fresh()->forceFill(['noise' => 39.9, 'opened' => $tree->nodesOf(NodeKind::Firewall)])->save();
        $this->simulator(1.0, 0)->take($this->character->fresh(), $run->fresh(), RunAction::Move, node: $from);
        $this->assertTrue($run->fresh()->alerted);
        $this->assertLessThan(0.0, $book());

        $this->simulator(0.0)->take($this->character->fresh(), $run->fresh(), RunAction::Move, node: $log);
        $this->simulator(0.0)->take($this->character->fresh(), $run->fresh(), RunAction::Wipe);
        $run = $run->fresh();

        $this->assertTrue($run->log_wiped);
        $this->assertSame(0.0, $book(), 'what the alert cost came back');
        $this->assertFalse($run->noticed(), 'so the system does not lock down after the run');
        $this->assertSame(RunRefusal::NotHere, $this->simulator(0.0)->take($this->character->fresh(), $run, RunAction::Wipe)->refusal, 'once');
    }

    /** A grade 7 tree run on the entry, its sentries replaced by one of ICE I posted at `$post`, standing on `$at`. */
    private function sentry(int $post, int $at, bool $awake = true, bool $fast = false): Run
    {
        System::query()->where('address', 'DEADBEEF')->update(['grade' => 7]);
        $run = $this->begin();
        $ice = Ice::query()->where('level', 1)->sole();
        $run->forceFill(['sentries' => [[
            'post' => $post, 'node' => $at, 'ice' => $ice->id, 'integrity' => (float) $ice->integrity,
            'awake' => $awake, 'fast' => $fast, 'target' => null,
        ]]])->save();

        return $run->fresh();
    }

    private function grade7(): RunTree
    {
        System::query()->where('address', 'DEADBEEF')->update(['grade' => 7]);

        return RunTree::of(System::query()->where('address', 'DEADBEEF')->sole());
    }

    public function test_a_tree_run_posts_its_sentries_asleep_a_level_below_the_system_s_ice(): void
    {
        $tree = $this->grade7();
        System::query()->where('address', 'DEADBEEF')->update(['ice_id' => Ice::query()->where('level', 3)->value('id')]);
        $run = $this->begin();

        // The barrier rides in the same list (awake, never moving); the sentries proper are the rest.
        $sentries = array_values(array_filter($run->sentries, static fn (array $s): bool => ! ($s['barrier'] ?? false)));
        $this->assertCount(2, $sentries, 'two at grade 7');
        $this->assertSame(array_column($tree->sentries, 'post'), array_column($sentries, 'node'));
        $this->assertSame([false, false], array_column($sentries, 'awake'));
        $this->assertSame(Ice::query()->where('level', 2)->value('id'), $sentries[0]['ice']);
    }

    public function test_an_awake_sentry_hunts_the_runner_and_strikes_on_reaching_them(): void
    {
        $tree = $this->grade7();
        $far = collect($tree->within($tree->entry, 2))->first(fn (int $n): bool => $tree->distance($tree->entry, $n) === 2);
        $run = $this->sentry($far, $far);
        $si = (float) $this->character->fresh()->current_synaptic_integrity;

        $this->simulator(0.0, 0)->take($this->character->fresh(), $run, RunAction::Hold);
        $sentry = $run->fresh()->sentries[0];
        $this->assertSame($run->id, $sentry['target']);
        $this->assertSame(1, $tree->distance($tree->entry, $sentry['node']), 'a Slow sentry moves one node');
        $this->assertSame($si, (float) $this->character->fresh()->current_synaptic_integrity);

        $this->simulator(0.0, 0)->take($this->character->fresh(), $run->fresh(), RunAction::Hold);
        $this->assertSame($tree->entry, $run->fresh()->sentries[0]['node']);
        $this->assertLessThan($si, (float) $this->character->fresh()->current_synaptic_integrity, 'it reached them and struck');
    }

    public function test_a_sentry_with_nobody_near_walks_back_to_its_post(): void
    {
        $tree = $this->grade7();
        $post = collect($tree->neighbours($tree->core))->first();
        $this->assertGreaterThan(3, $tree->distance($tree->entry, $tree->core));
        $run = $this->sentry($post, $tree->core);

        $this->simulator(0.0, 0)->take($this->character->fresh(), $run, RunAction::Hold);

        $this->assertSame($post, $run->fresh()->sentries[0]['node']);
        $this->assertNull($run->fresh()->sentries[0]['target']);
    }

    public function test_stepping_onto_a_sleeping_sentry_can_wake_it_and_the_alert_wakes_them_all(): void
    {
        $tree = $this->grade7();
        $next = collect($tree->neighbours($tree->entry))->first(fn (int $n): bool => $tree->nodes[$n]['kind'] !== NodeKind::Trap);
        $run = $this->sentry($next, $next, awake: false);
        $run->forceFill(['opened' => $tree->nodesOf(NodeKind::Firewall)])->save();

        $this->simulator(1.0, 1)->take($this->character->fresh(), $run->fresh(), RunAction::Move, node: $next);
        $this->assertTrue($run->fresh()->sentries[0]['awake'], 'a failed stealth roll against its detection');

        // Asleep again, far away: the alert (a failed step back filling the bar) wakes it all the same.
        $run = $run->fresh();
        $run->forceFill(['sentries' => [['awake' => false, 'node' => $tree->core, 'post' => $tree->core] + $run->sentries[0]], 'noise' => 39.9])->save();
        $this->simulator(1.0, 1)->take($this->character->fresh(), $run->fresh(), RunAction::Move, node: $tree->entry);
        $this->assertTrue($run->fresh()->alerted);
        $this->assertTrue($run->fresh()->sentries[0]['awake']);
    }

    public function test_attack_hits_an_awake_sentry_on_the_runner_s_node_and_a_broken_one_stays_broken(): void
    {
        $tree = $this->grade7();
        $breaker = $this->ownedBy($this->character, Item::factory()->program(ProgramKind::Icebreaker, qflops: 0.1, memory: 0.1, storage: 0.1,
            modifiers: [['op' => 'add', 'amount' => 100, 'target' => ['id' => 'icebreaker_damage']]])->create());
        $this->assertTrue(app(ProgramRunner::class)->start($this->character->fresh(), $breaker, true)->succeeded());
        $run = $this->sentry($tree->entry, $tree->entry);
        $here = TreeView::for($run->fresh(), $this->character->fresh(), app(Simulator::class), null)->sentriesHere[0];
        $this->assertGreaterThan(0, $here['max']);
        $this->actingAs($this->character->fresh()->user)->get(route('run.show'))->assertOk()
            ->assertSee($here['integrity'].' / '.$here['max'])->assertDontSee(Text::get('cyberspace.run.ice_none'));

        $this->assertNull($this->simulator(0.0, 1)->take($this->character->fresh(), $run, RunAction::Attack)->refusal);
        $sentry = $run->fresh()->sentries[0];
        $this->assertSame(0.0, (float) $sentry['integrity']);

        $this->assertSame(RunRefusal::NotHere, $this->simulator(0.0, 1)->take($this->character->fresh(), $run->fresh(), RunAction::Attack)->refusal, 'nothing left to hit');
        $this->assertSame($tree->entry, $run->fresh()->sentries[0]['node'], 'a broken sentry goes nowhere');
    }

    public function test_the_ice_controller_posts_a_sentry_every_third_turn_after_the_alert_until_shut_down(): void
    {
        $tree = $this->grade7();
        $controller = $tree->nodesOf(NodeKind::Controller)[0];
        $run = $this->begin();
        $this->assertSame(['ticks' => 0, 'spawned' => 0, 'down' => false], $run->controller);
        $run->forceFill(['sentries' => [], 'alerted' => true])->save();

        foreach (range(1, 3) as $turn) {
            $this->simulator(0.0, 1)->take($this->character->fresh(), $run->fresh(), RunAction::Hold);
        }

        $run = $run->fresh();
        $this->assertCount(1, $run->sentries);
        $this->assertSame($controller, $run->sentries[0]['post']);
        $this->assertTrue($run->sentries[0]['awake']);

        $os = Skill::factory()->create(['slug' => 'lattice', 'title' => 'Lattice', 'category' => 'Operating Systems', 'rank' => 4]);
        System::query()->where('address', 'DEADBEEF')->update(['os_skill_id' => $os->id]);
        $run->forceFill(['node' => $controller, 'sentries' => []])->save();
        $this->simulator(0.0, 1)->take($this->character->fresh(), $run->fresh(), RunAction::Shutdown);
        $this->assertTrue($run->fresh()->controller['down']);

        foreach (range(1, 3) as $turn) {
            $this->simulator(0.0, 1)->take($this->character->fresh(), $run->fresh(), RunAction::Hold);
        }

        $this->assertSame([], $run->fresh()->sentries, 'shut down, it posts nothing more');
    }

    public function test_a_cordon_walks_onto_the_way_out_and_cuts_a_node_after_its_countdown(): void
    {
        $tree = $this->grade7();
        $run = $this->sentry($tree->core, $tree->core);
        // Mapped, so the whole tree is drawn and the countdown can be seen wherever it is.
        $run->forceFill(['node' => $tree->core, 'mapped' => true, 'sentries' => [['cordon' => true, 'countdown' => null] + $run->sentries[0]]])->save();

        for ($turn = 0; $turn < 12 && $run->fresh()->cutNodes() === []; $turn++) {
            $this->simulator(0.0, 1)->take($this->character->fresh(), $run->fresh(), RunAction::Hold);

            if (($run->fresh()->sentries[0]['countdown'] ?? null) !== null) {
                $node = (int) $run->fresh()->sentries[0]['node'];
                $this->assertNotNull(collect(TreeView::for($run->fresh(), $this->character->fresh(), app(Simulator::class), null)->nodes)->firstWhere('key', $node)['cutting'], 'the countdown shows on the map');
            }
        }

        $cut = $run->fresh()->cutNodes();
        $this->assertCount(1, $cut);
        $this->assertContains($tree->nodes[$cut[0]]['kind'], [NodeKind::Relay, NodeKind::Switch, NodeKind::Port, NodeKind::Conduit]);
        $this->assertSame($tree->core, $run->fresh()->node, 'the runner never moved');
        // The log names what it cut.
        $named = Text::get('cyberspace.tree.node.'.$tree->nodes[$cut[0]]['kind']->value);
        $this->assertContains(Text::get('cyberspace.run.log.tree.cordon_cuts', ['node' => $named]), array_column($run->fresh()->log, 'text'));
        $this->assertContains(Text::get('cyberspace.run.log.tree.cordon_starts', ['node' => $named, 'turns' => 3]), array_column($run->fresh()->log, 'text'), 'the highest roll: three turns');
    }

    public function test_a_cordon_attacked_while_it_cuts_fights_its_attackers_and_cuts_on_once_they_are_gone(): void
    {
        $tree = $this->grade7();
        $breaker = $this->ownedBy($this->character, Item::factory()->program(ProgramKind::Icebreaker, qflops: 0.1, memory: 0.1, storage: 0.1,
            modifiers: [['op' => 'add', 'amount' => 1, 'target' => ['id' => 'icebreaker_damage']]])->create());
        $this->assertTrue(app(ProgramRunner::class)->start($this->character->fresh(), $breaker, true)->succeeded());
        $node = $tree->neighbours($tree->entry)[0];
        $run = $this->sentry($node, $node);
        $run->forceFill(['node' => $node, 'sentries' => [['cordon' => true, 'countdown' => 2] + $run->sentries[0]]])->save();
        $si = (float) $this->character->fresh()->current_synaptic_integrity;

        $this->assertNull($this->simulator(0.0, 0)->take($this->character->fresh(), $run->fresh(), RunAction::Attack)->refusal);
        $cordon = $run->fresh()->sentries[0];
        $this->assertSame(2, $cordon['countdown'], 'engaged, its cut holds');
        $this->assertSame([$run->id], $cordon['engaged']);
        $this->assertContains(Text::get('cyberspace.run.log.tree.cordon_engaged'), array_column($run->fresh()->log, 'text'));
        $this->assertLessThan($si, (float) $this->character->fresh()->current_synaptic_integrity, 'and it fights back');

        $this->simulator(0.0, 0)->take($this->character->fresh(), $run->fresh(), RunAction::Hold);
        $this->assertSame(2, $run->fresh()->sentries[0]['countdown'], 'still fighting while its attacker stays');

        // The attacker steps away: it goes back to its cut, and the countdown runs on.
        $this->simulator(0.0, 0)->take($this->character->fresh(), $run->fresh(), RunAction::Move, node: $tree->entry);
        $cordon = $run->fresh()->sentries[0];
        $this->assertSame(1, $cordon['countdown']);
        $this->assertArrayNotHasKey('engaged', $cordon);
        $this->assertContains(Text::get('cyberspace.run.log.tree.cordon_resumes'), array_column($run->fresh()->log, 'text'));
    }

    public function test_a_deep_nmap_reaches_further_finds_traps_and_reads_sentries(): void
    {
        [$run, $trap, $from] = $this->trap(TrapEffect::Alarm);
        $tree = RunTree::of(System::query()->where('address', $run->address)->sole());
        $run->forceFill(['sentries' => [['post' => $trap, 'node' => $trap, 'ice' => Ice::query()->where('level', 1)->value('id'),
            'integrity' => 30.0, 'awake' => false, 'fast' => true, 'target' => null, 'cordon' => true, 'countdown' => null]]])->save();
        $this->runNmapper(1, deep: true);

        $this->simulator(0.0)->take($this->character->fresh(), $run->fresh(), RunAction::Nmap);
        $run = $run->fresh();

        $this->assertSame([], array_values(array_diff($tree->within($from, 1), $run->knownNodes())), 'the nearest ring first');
        $this->assertSame('found', $run->trapStates()[$trap]);
        $this->assertTrue($run->sentries[0]['read']);

        $run->forceFill(['node' => $trap])->save();
        $this->assertStringContainsString(Text::get('cyberspace.tree.sentry_cordon'), TreeView::for($run->fresh(), $this->character->fresh(), app(Simulator::class), null)->sentriesHere[0]['name']);
    }

    public function test_a_honeypot_found_by_a_deep_nmap_is_named_and_disarmed_where_it_stands(): void
    {
        for ($i = 0; ; $i++) {
            $tree = RunTree::generate($address = sprintf('%08X', crc32("honey-{$i}")), 7, SystemType::Silo);
            $pots = array_values(array_filter($tree->nodesOf(NodeKind::Vault), static fn (int $n): bool => $tree->isHoneypot($n)));

            if ($pots !== []) {
                break;
            }
        }

        $os = Skill::factory()->create(['slug' => 'lattice', 'title' => 'Lattice', 'category' => 'Operating Systems', 'rank' => 4]);
        System::query()->where('address', 'DEADBEEF')->update(['address' => $address, 'grade' => 7, 'type' => SystemType::Silo->value, 'os_skill_id' => $os->id]);
        $run = $this->begin();
        $run->forceFill(['node' => $pots[0], 'sentries' => []])->save();
        $this->runNmapper(1, deep: true);

        $this->simulator(0.0)->take($this->character->fresh(), $run->fresh(), RunAction::Nmap);
        $view = TreeView::for($run->fresh(), $this->character->fresh(), app(Simulator::class), null);
        $this->assertStringStartsWith(Text::get('cyberspace.tree.honeypot_label', ['effect' => '']), collect($view->nodes)->firstWhere('key', $pots[0])['label']);
        $this->assertContains($pots[0], array_column($view->disarm, 0));

        $this->simulator(0.0)->take($this->character->fresh(), $run->fresh(), RunAction::Disarm, node: $pots[0]);
        $this->assertSame('spent', $run->fresh()->trapStates()[$pots[0]]);
    }

    public function test_an_egress_tunnel_makes_its_node_an_exit_easier_by_its_strength(): void
    {
        $tree = $this->grade7();
        $item = Item::factory()->program(ProgramKind::Script, qflops: 0.1, memory: 0.1, storage: 0.1, background: false, lifetime: 3600)->create();
        $item->program->forceFill(['tunnel_strength' => 4])->save();
        InventoryItem::create(['character_id' => $this->character->id, 'item_id' => $item->id, 'quantity' => 1]);
        $run = $this->begin();
        $node = $tree->path($tree->entry, $tree->core)[2];
        $run->forceFill(['node' => $node, 'sentries' => []])->save();

        $this->assertSame(RunRefusal::NoTunnel, $this->simulator(0.0)->take($this->character->fresh(), $run->fresh(), RunAction::Tunnel)->refusal, 'not without the skill');
        CharacterSkill::query()->create(['character_id' => $this->character->id, 'skill_id' => Skill::query()->where('slug', 'egress_tunneling')->value('id'), 'attained_level' => 1, 'skill_points' => 0]);
        $this->assertSame(RunRefusal::NotHere, $this->simulator(0.0)->take($this->character->fresh(), $run->fresh(), RunAction::Egress)->refusal);

        $this->simulator(0.0)->take($this->character->fresh(), $run->fresh(), RunAction::Tunnel);
        $run = $run->fresh();
        $this->assertNotNull($run->tunnel);
        $this->assertSame([$node], $run->tunnelNodes());
        $report = app(Simulator::class)->egressReport($this->character->fresh(), $run);
        $this->assertSame(4 + 1, $report->tunnel, 'the strength plus the Egress Tunneling level');
        $this->assertSame(13 - 5, $report->gradeDifficulty, "grade 6's 13, less the strength and the level");

        $this->assertNull($this->simulator(0.0)->take($this->character->fresh(), $run->fresh(), RunAction::Egress)->refusal);
        $this->assertSame(0, InventoryItem::query()->where('item_id', $item->id)->count(), 'spent with the run');
    }

    public function test_threats_are_marked_on_the_map_and_pulse_once_the_alert_has_gone_off(): void
    {
        [$run, $hub] = $this->hub();
        $run->forceFill(['known' => [...$run->knownNodes(), $hub]])->save();
        $threat = fn (): bool => collect(TreeView::for($run->fresh(), $this->character->fresh(), app(Simulator::class), null)->nodes)->firstWhere('key', $hub)['threat'];

        $this->assertTrue($threat(), 'a security hub nobody has subverted');
        $core = RunTree::of(System::query()->where('address', 'DEADBEEF')->sole())->core;
        $run->fresh()->forceFill(['known' => [...$run->fresh()->knownNodes(), $core]])->save();
        $this->assertTrue(collect(TreeView::for($run->fresh(), $this->character->fresh(), app(Simulator::class), null)->nodes)->firstWhere('key', $core)['ice'], 'the core\'s own ICE is marked');
        $this->actingAs($this->character->fresh()->user)->get(route('run.show'))->assertOk()
            ->assertSee('board__node--threat', false)->assertSee('data-tree-run="'.$run->id.'"', false)->assertDontSee('board--alert', false);

        $run->forceFill(['alerted' => true])->save();
        $this->actingAs($this->character->fresh()->user)->get(route('run.show'))->assertOk()->assertSee('board--alert', false);

        $run->fresh()->forceFill(['opened' => [$hub]])->save();
        $this->assertFalse($threat(), 'subverted, it is no longer a threat');
    }

    public function test_the_map_colours_named_nodes_by_kind_and_marks_a_sprung_trap(): void
    {
        [$run, $trap] = $this->trap(TrapEffect::Alarm);
        $this->simulator(0.0, 0)->take($this->character->fresh(), $run, RunAction::Move, node: $trap);
        $nodes = collect(TreeView::for($run->fresh(), $this->character->fresh(), app(Simulator::class), null)->nodes);

        $this->assertSame('entry', $nodes->firstWhere('key', RunTree::of(System::query()->where('address', $run->address)->sole())->entry)['kind']);
        $this->assertSame('trap', $nodes->firstWhere('key', $trap)['kind']);
        $this->assertTrue($nodes->firstWhere('key', $trap)['sprung']);
        $this->actingAs($this->character->fresh()->user)->get(route('run.show'))->assertOk()
            ->assertSee('board__node--sprung', false)->assertSee('data-kind="entry"', false);
    }

    public function test_the_run_screen_names_an_open_mission_against_the_system(): void
    {
        $system = System::query()->where('address', 'DEADBEEF')->sole();
        $entity = Entity::query()->interactable()->firstOrFail();
        $desk = Exchange::query()->create(['system_id' => System::factory()->at(2, 2)->ownedBy($entity)->create(['type' => 'exchange', 'grade' => 1])->id, 'grade' => 1, 'agent' => 'Mr Nakata', 'min_standing' => 0]);
        $job = Mission::query()->create([
            'character_id' => $this->character->id, 'entity_id' => $entity->id, 'exchange_id' => $desk->id,
            'action' => MissionAction::Extract, 'state' => MissionState::Accepted, 'quantity_required' => 0,
            'creds_reward' => 100, 'standing_reward' => 0.02, 'loyalty_reward' => 1, 'penalty' => 0.02, 'offered_at' => now(),
            'accepted_at' => now(), 'expires_at' => now()->addHours(5),
        ]);
        $job->targets()->create(['system_id' => $system->id, 'address' => $system->address]);
        $this->begin();

        $this->actingAs($this->character->fresh()->user)->get(route('run.show'))->assertOk()
            ->assertSeeText(Text::get('cyberspace.run.mission', ['action' => MissionAction::Extract->label(), 'entity' => $entity->name]));
    }

    public function test_a_tree_run_has_one_start_and_chooses_what_it_is_for_inside(): void
    {
        $this->actingAs($this->character->fresh()->user)->get(route('run.setup'))->assertOk()
            ->assertSeeText(Text::get('cyberspace.run.setup.start'))->assertDontSeeText(Text::get('cyberspace.run.setup.intent_burn'));

        $run = $this->begin();
        $this->assertSame(RunPurpose::Extraction, $run->purpose);

        // On the core, the run screen offers every core action, and an Install says which backdoor.
        $run->forceFill(['node' => $this->tree->core])->save();
        $this->actingAs($this->character->fresh()->user)->get(route('run.show'))->assertOk()
            ->assertSee('value="extract"', false)->assertSee('value="burn"', false)->assertSee('backdoor_kind=routing', false);
        $this->actingAs($this->character->fresh()->user)->post(route('run.act', ['backdoor_kind' => 'routing']), ['action' => 'install']);
        $this->assertSame(RunPurpose::Backdoor, $run->fresh()->purpose);
        $this->assertSame(BackdoorKind::Routing, $run->fresh()->backdoor_kind);

        // Flooding at the entry makes it a Denial run, the node's patience counting from this Flood.
        $run->fresh()->forceFill(['node' => $this->tree->entry, 'backdoored' => false])->save();
        $this->simulator(0.0)->take($this->character->fresh(), $run->fresh(), RunAction::Flood);
        $run = $run->fresh();
        $this->assertSame(RunPurpose::Denial, $run->purpose);
        $this->assertSame(1, $run->floodedTurns());
    }

    public function test_a_runner_on_a_node_the_cordon_cuts_is_thrown_to_the_neighbour_nearest_an_exit(): void
    {
        $tree = $this->grade7();
        $switch = $tree->neighbours($tree->entry)[0];
        $run = $this->sentry($switch, $switch);
        // Mid-cut, one turn left, the runner standing on it.
        $run->forceFill(['node' => $switch, 'sentries' => [['cordon' => true, 'countdown' => 1] + $run->sentries[0]]])->save();

        $this->simulator(0.0, 1)->take($this->character->fresh(), $run->fresh(), RunAction::Hold);
        $run = $run->fresh();

        $this->assertSame([$switch], $run->cutNodes());
        $this->assertSame($tree->entry, $run->node, 'the entry is the neighbour nearest an exit');
        $this->assertContains(Text::get('cyberspace.run.log.tree.cordon_thrown', ['node' => Text::get('cyberspace.tree.node.entry')]), array_column($run->log, 'text'));
    }

    public function test_barrier_ice_holds_the_fast_path_s_firewall_strikes_and_blocks_until_broken(): void
    {
        $tree = $this->grade7();
        $wall = $tree->barrier;
        $this->assertNotNull($wall);
        $this->assertSame(NodeKind::Firewall, $tree->nodes[$wall]['kind']);
        $this->assertSame('fast', $tree->nodes[$wall]['path']);

        $run = $this->begin();
        $barrier = collect($run->sentries)->firstWhere('barrier', true);
        $this->assertTrue($barrier['awake'], 'a wall, awake from the start');
        $this->assertSame(Ice::query()->where('level', 1)->value('id'), $barrier['ice'], 'a level below the system\'s own (level 1 at least)');

        // On its node with the firewall open: the barrier still holds the way on, and strikes.
        $run->forceFill(['node' => $wall, 'opened' => [$wall], 'sentries' => [$barrier]])->save();
        $on = collect($tree->edges)->first(fn (array $e): bool => $e[0] === $wall)[1];
        $back = collect($tree->edges)->first(fn (array $e): bool => $e[1] === $wall)[0];
        $this->assertSame(RunRefusal::BarrierHolds, $this->simulator(0.0)->take($this->character->fresh(), $run->fresh(), RunAction::Move, node: $on)->refusal);
        $si = (float) $this->character->fresh()->current_synaptic_integrity;
        $this->simulator(0.0, 0)->take($this->character->fresh(), $run->fresh(), RunAction::Hold);
        $this->assertLessThan($si, (float) $this->character->fresh()->current_synaptic_integrity, 'it strikes whoever is on its node');
        $this->assertNull($this->simulator(0.0)->take($this->character->fresh(), $run->fresh(), RunAction::Move, node: $back)->refusal, 'back is open');
        $this->assertSame($wall, $run->fresh()->sentries[0]['node'], 'and it never follows');

        // Broken, the way on opens.
        $run->fresh()->forceFill(['node' => $wall, 'sentries' => [['integrity' => 0.0] + $barrier]])->save();
        $this->assertNull($this->simulator(0.0)->take($this->character->fresh(), $run->fresh(), RunAction::Move, node: $on)->refusal);
    }

    public function test_a_refused_install_leaves_the_run_s_purpose_alone(): void
    {
        $run = $this->begin();

        // On the entry, not the core: the Install is refused, and the run is still an extraction.
        $this->actingAs($this->character->fresh()->user)->post(route('run.act', ['backdoor_kind' => 'siphon']), ['action' => 'install']);

        $this->assertSame(RunPurpose::Extraction, $run->fresh()->purpose);
        $this->assertNull($run->fresh()->backdoor_kind);
    }

    public function test_the_scan_s_tree_names_traps_but_never_a_honeypot(): void
    {
        for ($i = 0; ; $i++) {
            $tree = RunTree::generate($address = sprintf('%08X', crc32("honey-{$i}")), 7, SystemType::Silo);
            $pots = array_values(array_filter($tree->nodesOf(NodeKind::Vault), static fn (int $n): bool => $tree->isHoneypot($n)));

            if ($pots !== [] && $tree->nodesOf(NodeKind::Trap) !== []) {
                break;
            }
        }

        System::query()->where('address', 'DEADBEEF')->update(['address' => $address, 'grade' => 7, 'type' => SystemType::Silo->value]);
        $nodes = collect(TreeView::ofScan(System::query()->where('address', $address)->sole(), 3)->nodes);

        $this->assertSame(Text::get('cyberspace.tree.node.vault'), $nodes->firstWhere('key', $pots[0])['label']);
        $this->assertStringStartsWith(Text::get('cyberspace.tree.trap_label', ['effect' => '']), $nodes->firstWhere('key', $tree->nodesOf(NodeKind::Trap)[0])['label']);
    }

    public function test_a_maintenance_port_is_an_exit_asking_the_grade_below_s_difficulty(): void
    {
        // A grade 3 compute tree with a rich path, and so a port.
        for ($i = 0; RunTree::generate($address = sprintf('%08X', crc32("port-{$i}")), 3, SystemType::Compute)->nodesOf(NodeKind::Port) === []; $i++);
        System::query()->where('address', 'DEADBEEF')->update(['address' => $address]);
        $tree = RunTree::of(System::query()->where('address', $address)->sole());
        $port = $tree->nodesOf(NodeKind::Port)[0];

        $run = $this->begin();
        $atEntry = app(Simulator::class)->egressReport($this->character->fresh(), $run);
        $run->forceFill(['node' => $port])->save();
        $atPort = app(Simulator::class)->egressReport($this->character->fresh(), $run->fresh());

        $this->assertFalse($atEntry->port);
        $this->assertSame(7, $atEntry->gradeDifficulty);
        $this->assertTrue($atPort->port);
        $this->assertSame(5, $atPort->gradeDifficulty, "grade 2's");
        $this->assertNull($this->simulator(0.0)->refusal($this->character->fresh(), $run->fresh(), RunAction::Flood, null));

        $result = $this->simulator(0.0)->take($this->character->fresh(), $run->fresh(), RunAction::Egress);
        $this->assertNull($result->refusal);
        $this->assertSame(RunEnding::Egress, $result->ending);
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

    public function test_a_backdoor_holder_starts_at_the_port_unless_they_choose_the_entry(): void
    {
        // A grade 3 silo always has a rich path, and so a maintenance port.
        System::query()->where('address', 'DEADBEEF')->update(['type' => SystemType::Silo->value]);
        $system = System::query()->where('address', 'DEADBEEF')->sole();
        $tree = RunTree::of($system);
        (new Backdoors)->install($this->character, $system, BackdoorKind::Siphon);

        $this->checks(1.0);
        $run = app(RunStarter::class)->start($this->character->fresh(), RunPurpose::Extraction)->run;
        $this->assertSame($tree->nodesOf(NodeKind::Port)[0], $run->node);
        $this->assertSame(0.0, (float) $run->noise, 'the arrival rolls are skipped');
        app(RunStarter::class)->end($this->character->fresh(), $run, RunEnding::Egress);

        $run = app(RunStarter::class)->start($this->character->fresh(), RunPurpose::Extraction, atEntry: true)->run;
        $this->assertSame($tree->entry, $run->node);
        $this->assertSame(0.0, (float) $run->noise, 'still skipped at the entry');
        app(RunStarter::class)->end($this->character->fresh(), $run, RunEnding::Egress);

        // No port (grade 2 has one path): the core, as before.
        System::query()->where('address', 'DEADBEEF')->update(['grade' => 2]);
        $run = app(RunStarter::class)->start($this->character->fresh(), RunPurpose::Extraction)->run;
        $this->assertSame(RunTree::of($system->fresh())->core, $run->node);
    }

    public function test_the_briefing_offers_where_to_start_only_to_a_backdoor_holder(): void
    {
        $this->actingAs($this->character->fresh()->user)->get(route('run.setup'))->assertOk()->assertDontSee('name="start_at"', false);

        (new Backdoors)->install($this->character, System::query()->where('address', 'DEADBEEF')->sole(), BackdoorKind::Siphon);

        $this->actingAs($this->character->fresh()->user)->get(route('run.setup'))->assertOk()
            ->assertSee('name="start_at" value="core"', false)->assertSee('name="start_at" value="entry"', false)
            ->assertSeeText(Text::get(RunTree::of(System::query()->where('address', 'DEADBEEF')->sole())->nodesOf(NodeKind::Port) === [] ? 'cyberspace.tree.start_core' : 'cyberspace.tree.start_port'));
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

    /** @return array{0: Run, 1: Run} The owner's run and the mate's, launched together. */
    public function test_what_runs_saw_fills_the_tree_facts_a_scan_has_not_read_in_brass(): void
    {
        $tree = $this->grade7();
        $run = $this->begin();
        $path = $tree->path($tree->entry, $tree->core);
        $run->forceFill(['known' => array_slice($path, 0, 3)])->save();
        $facts = fn (SystemScan $scan): array => collect($scan->facts($this->character->fresh()))->keyBy('key')->all();

        $none = $facts(new SystemScan(['address' => 'DEADBEEF', 'tree' => 0]));
        $this->assertSame(Text::plural('cyberspace.reading.node_count', 3), $none['nodes']['value'], 'the nodes seen');
        $this->assertTrue($none['nodes']['partial']);
        $this->assertNotNull($none['nodes']['more'], 'the count still opens what was seen');
        $this->assertStringContainsString(Text::get('cyberspace.reading.layout_seen', ['paths' => '']), ' '.$none['layout']['value'].' ');
        $this->assertSame(Text::get('cyberspace.reading.traps_none_found'), $none['traps']['value']);

        // Seen as far as the core: the moves are known too. A scan that read the Layout is whole, in ember.
        $run->forceFill(['known' => $path])->save();
        $read = $facts(new SystemScan(['address' => 'DEADBEEF', 'tree' => 1]));
        $this->assertFalse($read['layout']['partial']);
        $this->assertTrue($read['nodes']['partial'], 'the Nodes still come from the runs');
        $this->assertStringContainsString(Text::plural('cyberspace.reading.moves', $tree->distance($tree->entry, $tree->core)), $facts(new SystemScan(['address' => 'DEADBEEF', 'tree' => 0]))['layout']['value']);

        // Over, as runs that explored a system usually are: what they saw still counts.
        app(RunStarter::class)->end($this->character->fresh(), $run->fresh(), RunEnding::Jackout);
        $this->assertTrue($facts(new SystemScan(['address' => 'DEADBEEF', 'tree' => 0]))['nodes']['partial']);
        $this->actingAs($this->character->fresh()->user)->get(route('cyberspace.tree', ['address' => 'DEADBEEF', 'nodes' => 1]))->assertOk()
            ->assertSee(Text::get('cyberspace.tree.node.entry'));

        // View tree: no scan, so only what the run saw, named, and the nodes next to it as "?".
        $view = TreeView::ofScan(System::query()->where('address', 'DEADBEEF')->sole(), 0, Explored::of($this->character->fresh(), 'DEADBEEF'));
        $shown = collect($view->nodes)->keyBy('key');
        foreach ($path as $n) {
            $this->assertTrue($shown[$n]['known'], 'a node the run stood on is named');
        }
        $unseen = collect($tree->neighbours($path[1]))->first(fn (int $n): bool => ! in_array($n, $path, true));
        if ($unseen !== null) {
            $this->assertSame('?', $shown[$unseen]['label'], 'its neighbours show as unknown');
        }
        $this->assertLessThan(count($tree->nodes), $shown->count(), 'the rest of the shape stays hidden without the Layout');
        $this->actingAs($this->character->fresh()->user)->get(route('cyberspace.tree', ['address' => 'DEADBEEF']))->assertOk();
    }

    /** A grade-$grade routing backdoor for $runner, and the skill to route through it. */
    private function routeFor(Character $runner, int $n, int $grade): System
    {
        $skill = Skill::query()->firstWhere('slug', 'a_routing_skill') ?? Skill::factory()->rank(4)->governedBy('intellect', 'memory')->create([
            'slug' => 'a_routing_skill',
            'modifiers' => array_map(static fn (int $at): array => ['op' => 'add', 'level' => $at, 'amount' => 1, 'target' => ['id' => 'routing_hops']], range(1, 5)),
        ]);
        CharacterSkill::query()->firstOrCreate(['character_id' => $runner->id, 'skill_id' => $skill->id], ['attained_level' => 3, 'skill_points' => 0]);
        $system = System::factory()->at(40 + $n, 1)->grade($grade)->create(['address' => 'RELAY00'.$n]);
        (new Backdoors)->install($runner->fresh(), $system, BackdoorKind::Routing);

        return $system;
    }

    public function test_the_owner_picks_whose_network_the_whole_crew_comes_in_over(): void
    {
        $this->checks(0.0);
        $mate = $this->runner();
        $stranger = $this->runner();
        $this->routeFor($this->character, 1, 3);
        $relay = $this->routeFor($mate, 2, 7);
        $parties = new Parties;
        $parties->open($this->character);
        $this->assertSame($this->character->id, RunParty::query()->sole()->chain_character_id, "the owner's own, to begin with");
        $this->assertNull($parties->invite($this->character, $mate->handle));
        $this->assertNull($parties->accept($mate));

        $this->assertSame([$this->character->id, $mate->id], array_map(static fn (array $n): int => $n['character']->id, $parties->networks(RunParty::query()->sole())));
        $this->assertSame(PartyRefusal::NoSuchRunner, $parties->network($this->character, $stranger->id), 'only a runner in the party');
        $this->assertSame(PartyRefusal::NoParty, $parties->network($mate, $mate->id), 'only the owner picks');
        $this->assertNull($parties->network($this->character, $mate->id));
        $this->actingAs($this->character->fresh()->user)->get(route('run.setup'))->assertOk()->assertSee(Text::get('cyberspace.party.network'));

        $this->assertTrue($parties->launch($this->character, RunPurpose::Extraction, null, [], app(RunStarter::class))->succeeded());
        $runs = Run::query()->where('run_party_id', RunParty::query()->sole()->id)->get();
        $this->assertCount(2, $runs);
        $runs->each(fn (Run $r) => $this->assertSame([$relay->id], array_values((array) $r->chain), "every runner comes in over the mate's network"));
    }

    public function test_the_picked_network_holds_the_crew_s_one_trace_with_its_runner_s_backdoors(): void
    {
        System::query()->where('address', 'DEADBEEF')->update(['tracer_rate' => 10]);
        $this->checks(0.0);
        $mate = $this->runner();
        $relay = $this->routeFor($mate, 2, 9);
        $parties = new Parties;
        $parties->open($this->character);
        $this->assertNull($parties->invite($this->character, $mate->handle));
        $this->assertNull($parties->accept($mate));
        $this->assertNull($parties->network($this->character, $mate->id));
        $this->assertTrue($parties->launch($this->character, RunPurpose::Extraction, null, [], app(RunStarter::class))->succeeded());
        $runs = Run::query()->where('run_party_id', RunParty::query()->sole()->id)->orderBy('id')->get();
        [$a, $b] = [$runs->firstWhere('character_id', $this->character->id), $runs->firstWhere('character_id', $mate->id)];
        $a->forceFill(['detected' => true, 'trace' => 0])->save();

        $this->assertSame([9], $a->fresh()->routingGrades(), "the owner's run reads the mate's hop: theirs is the network");
        $this->assertFalse($a->fresh()->traceChain()[1]['gone']);

        // The highest roll: the grade 9 hop holds the trace.
        $this->app->instance(Simulator::class, Simulator::fromConfig(static fn (int $min, int $max): int => $max, app(Resolver::class)));
        $turns = app(PartyTurns::class);
        $turns->submit($a->fresh(), RunAction::Hold);
        $turns->submit($b->fresh(), RunAction::Hold);

        $this->assertSame(0, (int) $a->fresh()->trace, 'held at the hop');
        $this->assertSame(0, (int) $b->fresh()->trace);
        $this->assertSame([$relay->id], array_values((array) $b->fresh()->chain));
    }

    public function test_a_picked_runner_who_cannot_go_in_hands_the_network_back_to_the_owner(): void
    {
        $this->checks(0.0);
        $mate = $this->runner();
        $away = $this->runner();
        $this->routeFor($away, 2, 9);
        $parties = new Parties;
        $parties->open($this->character);
        foreach ([$mate, $away] as $runner) {
            $this->assertNull($parties->invite($this->character, $runner->handle));
            $this->assertNull($parties->accept($runner));
        }
        $this->assertNull($parties->network($this->character, $away->id));
        $away->forceFill(['grid_x' => (int) $away->grid_x + 5])->save();

        $this->assertTrue($parties->launch($this->character, RunPurpose::Extraction, null, [], app(RunStarter::class))->succeeded());
        $this->assertSame($this->character->id, RunParty::query()->sole()->chain_character_id);
        Run::query()->where('run_party_id', RunParty::query()->sole()->id)->get()
            ->each(fn (Run $r) => $this->assertSame([], array_values((array) $r->chain), 'not the network of a runner who is not in'));
    }

    public function test_the_picked_network_falls_back_to_the_owner_s_when_its_runner_leaves_and_none_is_no_route(): void
    {
        $mate = $this->runner();
        $parties = new Parties;
        $parties->open($this->character);
        $this->assertNull($parties->invite($this->character, $mate->handle));
        $this->assertNull($parties->accept($mate));
        $this->assertNull($parties->network($this->character, $mate->id));

        $parties->leave($mate);
        $this->assertSame($this->character->id, RunParty::query()->sole()->chain_character_id);

        $this->assertNull($parties->network($this->character, null));
        $this->assertNull(RunParty::query()->sole()->chain_character_id, 'no network');
    }

    public function test_a_party_s_one_trace_moves_once_a_round_not_once_a_runner(): void
    {
        System::query()->where('address', 'DEADBEEF')->update(['tracer_rate' => 10]);
        [$a, $b] = $this->partyRuns();
        $a->forceFill(['detected' => true, 'trace' => 0])->save();

        $this->app->instance(Simulator::class, Simulator::fromConfig(static fn (int $min, int $max): int => $min, app(Resolver::class)));
        $turns = app(PartyTurns::class);
        $turns->submit($a->fresh(), RunAction::Hold);
        $turns->submit($b->fresh(), RunAction::Hold);

        $this->assertSame(10, (int) $a->fresh()->trace, 'one step for the round');
        $this->assertSame(10, (int) $b->fresh()->trace, 'and the same meter for everybody');

        $a->fresh()->forceFill(['trace' => 95])->save();
        $b->fresh()->forceFill(['trace' => 95])->save();
        $turns->submit($a->fresh(), RunAction::Hold);
        $turns->submit($b->fresh(), RunAction::Hold);
        $this->assertSame(100, (int) $b->fresh()->trace, 'it reaches everybody at once');
        $this->assertSame(1, collect($b->fresh()->log)->where('text', Text::get('cyberspace.run.log.traced'))->count(), 'told once, in the one log');
    }

    private function partyRuns(): array
    {
        $this->checks(0.0);
        $mate = $this->runner();
        $parties = new Parties;
        $parties->open($this->character);
        $this->assertNull($parties->invite($this->character, $mate->handle));
        $this->assertNull($parties->accept($mate));
        $this->assertTrue($parties->launch($this->character, RunPurpose::Extraction, null, [], app(RunStarter::class))->succeeded());
        $runs = Run::query()->where('run_party_id', RunParty::query()->sole()->id)->orderBy('id')->get();

        return [$runs->firstWhere('character_id', $this->character->id), $runs->firstWhere('character_id', $mate->id)];
    }

    public function test_one_runner_s_loopback_stalls_an_ally_s_trace_in_a_party(): void
    {
        System::query()->where('address', 'DEADBEEF')->update(['type' => SystemType::Relay->value]);
        $relay = RunTree::of(System::query()->where('address', 'DEADBEEF')->sole())->nodesOf(NodeKind::Relay)[0];
        $item = Item::factory()->program(ProgramKind::Script, qflops: 0.1, memory: 0.1, storage: 0.1, background: false, lifetime: 3600)->create();
        $item->program->forceFill(['loopback_strength' => 20])->save();
        InventoryItem::create(['character_id' => $this->character->id, 'item_id' => $item->id, 'quantity' => 1]);
        [$a, $b] = $this->partyRuns();
        $a->forceFill(['node' => $relay])->save();
        $b->forceFill(['detected' => true, 'trace' => 0])->save();

        $this->app->instance(Simulator::class, Simulator::fromConfig(static fn (int $min, int $max): int => $min, app(Resolver::class)));
        $turns = app(PartyTurns::class);
        $turns->submit($a->fresh(), RunAction::Loopback);
        $turns->submit($b->fresh(), RunAction::Hold);
        $this->assertNotNull($a->fresh()->loopback);

        $turns->submit($a->fresh(), RunAction::Hold);
        $turns->submit($b->fresh(), RunAction::Hold);

        $this->assertSame(0, (int) $b->fresh()->trace, "the ally's trace stalls while the Loopback holds");
        $this->assertNotNull($a->fresh()->loopback, 'and it held');
    }

    public function test_a_party_s_sentries_take_one_turn_a_round(): void
    {
        $tree = $this->grade7();
        [$a, $b] = $this->partyRuns();
        $far = collect($tree->within($tree->entry, 3))->first(fn (int $n): bool => $tree->distance($tree->entry, $n) === 3);
        $ice = Ice::query()->where('level', 1)->sole();
        $sentries = [['post' => $far, 'node' => $far, 'ice' => $ice->id, 'integrity' => (float) $ice->integrity, 'awake' => true,
            'fast' => false, 'target' => null, 'cordon' => false, 'countdown' => null]];
        $a->forceFill(['sentries' => $sentries])->save();
        $b->forceFill(['sentries' => $sentries])->save();

        $this->app->instance(Simulator::class, Simulator::fromConfig(static fn (int $min, int $max): int => $max, app(Resolver::class)));
        $turns = app(PartyTurns::class);
        $turns->submit($a->fresh(), RunAction::Hold);
        $turns->submit($b->fresh(), RunAction::Hold);

        $this->assertSame(2, $tree->distance($tree->entry, $a->fresh()->sentries[0]['node']), 'one Slow step for the round, not one per runner');
        $this->assertSame($a->fresh()->sentries, $b->fresh()->sentries, 'one set of sentries for the party');
    }

    public function test_a_party_shares_one_bar_the_alert_and_the_trace(): void
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
        $this->assertTrue($b->detected, 'one trace for the crew: anybody picked up starts it');

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
