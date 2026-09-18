<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Checks\CheckRoller;
use App\Cyberspace\BackdoorKind;
use App\Cyberspace\Backdoors;
use App\Cyberspace\ChainBuilder;
use App\Cyberspace\Lockdown;
use App\Cyberspace\RunAction;
use App\Cyberspace\RunPurpose;
use App\Cyberspace\RunStarter;
use App\Cyberspace\Simulator;
use App\Models\Backdoor;
use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\Run;
use App\Models\Skill;
use App\Models\System;
use App\Stats\Resolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/**
 * The route a run takes, built rather than picked.
 *
 * Every routing backdoor is free to include and hinders by its machine's
 * grade, so there is nothing to choose: the chain is everything available,
 * best first, capped by skill. What a player decides is which machines to hold
 * one, which is a decision made one backdoor run at a time.
 */
final class RoutingChainTest extends TestCase
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
            'grid_x' => 8, 'grid_y' => 8, 'egress_trace_at' => 0, 'egress_si_at' => 0,
        ])->save();
        $this->character->refresh();
    }

    // ---- building it --------------------------------------------------------

    /** Untrained routes through nothing, however many backdoors are held. */
    public function test_without_the_skill_there_is_no_chain(): void
    {
        $this->hold($this->relay(1, 5));
        $this->hold($this->relay(2, 7));

        $this->assertCount(0, $this->chain());
    }

    public function test_it_takes_the_best_machines_first(): void
    {
        $this->trainRouting(2);

        $this->hold($this->relay(1, 3));
        $this->hold($this->relay(2, 9));
        $this->hold($this->relay(3, 6));

        $grades = $this->chain()->map(static fn (System $hop): int => (int) $hop->grade)->all();

        $this->assertSame([9, 6], $grades, 'best first, and only as many as the skill allows');
    }

    /** A siphon is not a hop and never appears in the list. */
    public function test_siphons_are_not_eligible(): void
    {
        $this->trainRouting(5);
        $this->hold($this->relay(1, 9), BackdoorKind::Siphon);
        $this->hold($this->relay(2, 4));

        $grades = $this->chain()->map(static fn (System $hop): int => (int) $hop->grade)->all();

        $this->assertSame([4], $grades);
    }

    /** Routing a run through the machine it is against is not a route. */
    public function test_the_target_is_never_in_its_own_chain(): void
    {
        $this->trainRouting(5);

        $target = $this->machine();
        $this->hold($target);
        $this->hold($this->relay(1, 5));

        $grades = $this->chain($target)->map(static fn (System $hop): int => (int) $hop->grade)->all();

        $this->assertSame([5], $grades);
    }

    // ---- carrying it --------------------------------------------------------

    /** Declared per run: the route is snapshotted when it begins. */
    public function test_a_run_carries_the_route_it_was_shown(): void
    {
        $this->trainRouting(3);
        $hops = [$this->relay(1, 9), $this->relay(2, 5)];
        foreach ($hops as $hop) {
            $this->hold($hop);
        }

        $this->machine();
        $run = $this->begin();

        $this->assertSame(
            array_map(static fn (System $hop): int => $hop->id, $hops),
            $run->chain,
        );
        $this->assertSame([9, 5], $run->routingGrades());
    }

    /** A backdoor installed after the run began is not on its route. */
    public function test_the_route_does_not_grow_under_a_live_run(): void
    {
        $this->trainRouting(3);
        $this->hold($this->relay(1, 9));
        $this->machine();

        $run = $this->begin();
        $this->hold($this->relay(2, 8));

        $this->assertCount(1, $run->fresh()->chain);
    }

    // ---- losing a hop of it -------------------------------------------------

    /**
     * LOSING ONE HOP IS NOT LOSING THE CHAIN. A completed trace is the only
     * thing that takes the whole route.
     */
    public function test_a_lost_hop_leaves_the_rest_standing(): void
    {
        $this->trainRouting(3);
        $first = $this->relay(1, 9);
        $second = $this->relay(2, 5);
        $this->hold($first);
        $this->hold($second);

        $this->machine();
        $run = $this->begin();

        // Somebody else's bad night on the first hop.
        Lockdown::fromConfig(static fn (int $min, int $max): int => $max)->shut($first);

        $this->assertSame([null, 5], $run->fresh()->routingGrades(), 'a gap, not a shift');
        $this->assertSame(1, $run->fresh()->liveHops());
    }

    /**
     * AND THE TRACE WALKS PAST THE GAP FOR FREE, in the same turn — a machine
     * that is not there hinders nothing and holds nothing up.
     */
    public function test_the_trace_walks_past_a_gap_without_spending_a_turn(): void
    {
        $this->trainRouting(3);
        $gone = $this->relay(1, 9);
        $standing = $this->relay(2, 9);
        $this->hold($gone);
        $this->hold($standing);

        $this->machine();
        $run = $this->begin(noticed: true);

        Lockdown::fromConfig(static fn (int $min, int $max): int => $max)->shut($gone);

        // A roll that passes a grade 9: the gap is stepped over and the live
        // hop behind it is the one actually attempted, both in this turn.
        $this->simulator(1)->take($this->character->fresh(), $run->fresh(), RunAction::Hold);

        $this->assertSame(2, $run->fresh()->trace_hop, 'past the gap and past the hop');
    }

    // ---- and losing all of it -----------------------------------------------

    /**
     * A TRACE THAT REACHED YOU FOLLOWED THE PATH ALL THE WAY HOME. Every
     * system on the route is exposed: the backdoors go and the machines shut.
     */
    public function test_a_completed_trace_burns_the_whole_chain(): void
    {
        $this->trainRouting(3);
        $first = $this->relay(1, 9);
        $second = $this->relay(2, 9);
        $this->hold($first);
        $this->hold($second);

        $this->machine();
        $run = $this->begin(noticed: true);

        // Straight to the end of the trace, and past the chain.
        $run->forceFill(['trace' => 99, 'trace_hop' => 2])->save();

        $this->simulator(1)->take($this->character->fresh(), $run->fresh(), RunAction::Hold);

        $this->assertSame(100, $run->fresh()->trace);
        $this->assertSame(0, Backdoor::query()->count(), 'every door on the route is gone');
        $this->assertTrue($first->fresh()->isLockedDown());
        $this->assertTrue($second->fresh()->isLockedDown());
    }

    // ---- and what the screen shows before any of it -------------------------

    public function test_the_setup_screen_shows_the_route_before_committing(): void
    {
        $this->trainRouting(2);
        $this->hold($this->relay(1, 9));
        $this->machine();

        $this->actingAs($this->character->user)
            ->get(route('cyberspace.index'))
            ->assertOk()
            ->assertSee('Go in')
            ->assertSee('Routed through')
            ->assertSee('Grade 9');
    }

    public function test_the_setup_screen_says_when_there_is_no_route(): void
    {
        $this->machine();

        $this->actingAs($this->character->user)
            ->get(route('cyberspace.index'))
            ->assertOk()
            ->assertSee('Routing is the skill for it');
    }

    /** A shut machine is not offered, which is the refusal said before it is made. */
    public function test_a_shut_machine_is_not_offered(): void
    {
        $system = $this->machine();
        Lockdown::fromConfig(static fn (int $min, int $max): int => $max)->shut($system);

        $this->actingAs($this->character->user)
            ->get(route('cyberspace.index'))
            ->assertOk()
            ->assertDontSee('Routed through');
    }

    // ---- fixtures ------------------------------------------------------------

    private function machine(): System
    {
        return System::factory()->at(8, 8)->grade(1)->withTracer(2, 4)->create(['address' => 'DEADBEEF']);
    }

    /** Somebody else's relay, somewhere else on the grid. */
    private function relay(int $n, int $grade): System
    {
        return System::factory()->at($n, 1)->grade($grade)->create(['address' => 'RELAY00'.$n]);
    }

    private function hold(System $system, BackdoorKind $kind = BackdoorKind::Routing): void
    {
        (new Backdoors)->install($this->character->fresh(), $system, $kind);
    }

    /** A skill of this test's own granting hops, since the catalogue is content. */
    private function trainRouting(int $level): void
    {
        $skill = Skill::factory()->rank(4)->governedBy('intellect', 'memory')->create([
            'slug' => 'a_routing_skill',
            'modifiers' => array_map(
                static fn (int $at): array => ['op' => 'add', 'level' => $at, 'amount' => 1, 'target' => ['id' => 'routing_hops']],
                range(1, 5),
            ),
        ]);

        CharacterSkill::query()->create([
            'character_id' => $this->character->id,
            'skill_id' => $skill->id,
            'attained_level' => $level,
            'skill_points' => 0,
        ]);

        $this->character->refresh();
    }

    private function chain(?System $target = null)
    {
        return ChainBuilder::fromConfig()->for($this->character->fresh(), $target);
    }

    private function begin(bool $noticed = false): Run
    {
        $this->instance(
            CheckRoller::class,
            CheckRoller::fromConfig(static fn (): float => $noticed ? 1.0 : 0.0),
        );

        return app(RunStarter::class)->start($this->character->fresh(), RunPurpose::Extraction)->run;
    }

    private function simulator(int $die): Simulator
    {
        return Simulator::fromConfig(
            static fn (int $min, int $max): int => min($max, max($min, $die)),
            app(Resolver::class),
            app(CheckRoller::class),
        );
    }
}
