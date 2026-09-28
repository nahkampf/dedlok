<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Checks\CheckRoller;
use App\Cyberspace\BackdoorKind;
use App\Cyberspace\Backdoors;
use App\Cyberspace\Burner;
use App\Cyberspace\Lockdown;
use App\Cyberspace\MapCell;
use App\Cyberspace\MapView;
use App\Cyberspace\Position;
use App\Cyberspace\RaidLedger;
use App\Cyberspace\RunAction;
use App\Cyberspace\RunPurpose;
use App\Cyberspace\RunStarter;
use App\Cyberspace\Simulator;
use App\Cyberspace\Sweep;
use App\Cyberspace\TurnResult;
use App\Models\Backdoor;
use App\Models\Character;
use App\Models\Ice;
use App\Models\Notification;
use App\Models\Run;
use App\Models\System;
use App\Notifications\Category;
use App\Stats\Resolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/**
 * Keeping a way back into a machine you have already been inside.
 *
 * What a backdoor buys is deliberately half a thing: the approach roll is
 * skipped for ever, and the core roll is untouched. What it costs is a run
 * that has to succeed, and what it gives back — the cleanup — is the only
 * thing in cyberspace that undoes a lockdown before it happens.
 */
final class BackdoorTest extends TestCase
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

    // ---- installing --------------------------------------------------------

    public function test_a_backdoor_run_leaves_a_way_back_in(): void
    {
        $system = $this->machine();
        $run = $this->begin(BackdoorKind::Routing);

        $this->act($run, RunAction::Install);

        $held = (new Backdoors)->heldOn($this->character->fresh(), $system);

        $this->assertNotNull($held);
        $this->assertSame(BackdoorKind::Routing, $held->kind);
        $this->assertTrue($run->fresh()->backdoored);
    }

    /** The kind is chosen at the door, with the purpose, and carried on the run. */
    public function test_the_kind_comes_from_the_door(): void
    {
        $system = $this->machine();
        $run = $this->begin(BackdoorKind::Siphon);

        $this->assertSame(BackdoorKind::Siphon, $run->backdoorKind());

        $this->act($run, RunAction::Install);

        $this->assertSame(BackdoorKind::Siphon, (new Backdoors)->heldOn($this->character->fresh(), $system)->kind);
    }

    /**
     * AN EXTRACTION RUN CANNOT INSTALL ONE, and the line it gets says nothing
     * about why — the same discipline every core-touch failure keeps.
     */
    public function test_the_wrong_purpose_installs_nothing(): void
    {
        $system = $this->machine();
        $run = $this->begin(null, RunPurpose::Extraction);

        $result = $this->act($run, RunAction::Install);

        $this->assertNull((new Backdoors)->heldOn($this->character->fresh(), $system));
        $this->assertFalse($run->fresh()->backdoored);
        $this->assertNotEmpty(array_filter(
            array_column($result->log, 'text'),
            static fn (string $line): bool => str_contains($line, 'nothing you can use'),
        ));
    }

    /** A backdoor run with no kind on it is a hand-built request, and is refused. */
    public function test_a_kindless_backdoor_run_installs_nothing(): void
    {
        $system = $this->machine();
        $run = $this->begin(null);

        $this->act($run, RunAction::Install);

        $this->assertNull((new Backdoors)->heldOn($this->character->fresh(), $system));
    }

    /**
     * GOING BACK IN CHANGES NOTHING. The kind is permanent, so a second run is
     * not a way to convert a siphon into a routing hop.
     */
    public function test_a_second_install_does_not_reroll_the_kind(): void
    {
        $system = $this->machine();
        (new Backdoors)->install($this->character, $system, BackdoorKind::Siphon);

        $run = $this->begin(BackdoorKind::Routing);
        $this->act($run, RunAction::Install);

        $this->assertSame(BackdoorKind::Siphon, (new Backdoors)->heldOn($this->character->fresh(), $system)->kind);
        $this->assertSame(1, Backdoor::query()->count(), 'and there is still one of it');
    }

    /** Two people may hold the same machine and neither knows about the other. */
    public function test_two_characters_may_hold_one_machine(): void
    {
        $system = $this->machine();
        $stranger = Character::factory()->create(['archetype' => 3]);

        (new Backdoors)->install($this->character, $system, BackdoorKind::Siphon);
        (new Backdoors)->install($stranger, $system, BackdoorKind::Routing);

        $this->assertSame(2, Backdoor::query()->count());
    }

    // ---- what it cleans up --------------------------------------------------

    /**
     * THE ONLY THING IN CYBERSPACE THAT CLEANS UP AFTER ITSELF. The install
     * erases the logs and resets what it tripped, so the machine does not shut
     * EVEN THOUGH the IDS fired and the ICE woke up.
     */
    /** Awake ICE guards the core: nothing is installed until it is broken. */
    public function test_awake_ice_stops_an_install(): void
    {
        $this->machine(iceLevel: 1);
        $run = $this->begin(BackdoorKind::Siphon, noticed: true);

        $this->act($run, RunAction::Install);

        $this->assertFalse((bool) $run->fresh()->backdoored);
        $this->assertSame(0, Backdoor::query()->count());
    }

    public function test_a_landed_install_leaves_no_lockdown_behind(): void
    {
        // No ICE: awake ICE would guard the core and the install could not
        // land. The tracer is what sees them.
        $system = $this->machine();
        $run = $this->begin(BackdoorKind::Routing, noticed: true);

        $this->assertTrue($run->fresh()->detected, 'it saw them on the way in');

        $this->act($run, RunAction::Install);
        $this->leave();

        $this->assertFalse($system->fresh()->isLockedDown(), 'and forgot about it');
    }

    /** A backdoor run that FAILED cleans up nothing. */
    public function test_a_failed_backdoor_run_shuts_the_machine_like_any_other(): void
    {
        $system = $this->machine();
        $this->begin(BackdoorKind::Routing, noticed: true);

        // Never touched the core: they were seen and left with nothing done.
        $this->leave();

        $this->assertTrue($system->fresh()->isLockedDown());
    }

    /** It does not move the raid counter either: the records it would read are gone. */
    public function test_installing_counts_no_raid(): void
    {
        $system = $this->machine();
        $run = $this->begin(BackdoorKind::Routing);

        $this->act($run, RunAction::Install);
        $this->leave();

        $this->assertSame(0, $system->fresh()->raids);
    }

    // ---- what it buys on the way back in ------------------------------------

    /**
     * HALF THE RISK PERMANENTLY. The approach roll is skipped on a machine you
     * hold — even one that would otherwise have seen them every time.
     */
    public function test_a_held_machine_does_not_look_up_when_you_arrive(): void
    {
        $system = $this->machine(iceLevel: 3);
        (new Backdoors)->install($this->character, $system, BackdoorKind::Routing);

        // Pinned so every watcher WOULD find them, if it were asked.
        $run = $this->begin(BackdoorKind::Routing, noticed: true);

        $this->assertFalse($run->detected, 'nothing looked up');
        $this->assertNull($run->ice_id, 'so nothing woke');
    }

    /**
     * AND NOTHING AT THE CORE. Extracting through a backdoor is still
     * stealing: the second look happens, and being seen there wakes the guard.
     */
    public function test_it_buys_nothing_at_the_core(): void
    {
        $system = $this->machine(iceLevel: 3);
        (new Backdoors)->install($this->character, $system, BackdoorKind::Routing);

        $run = $this->begin(BackdoorKind::Routing, noticed: true);
        $this->assertFalse($run->fresh()->detected);

        $this->act($run, RunAction::Install);

        $this->assertTrue($run->fresh()->detected, 'the core touch still rolled');
    }

    // ---- how one is lost ----------------------------------------------------

    /**
     * A STRANGER'S BAD NIGHT COSTS YOU A DOOR YOU WERE CAREFUL WITH.
     *
     * A backdoor keeps a machine open against ITS OWN triggers — a clean
     * install leaves no lockdown, and a machine you hold does not look up when
     * you arrive. It has never protected against somebody else being caught in
     * there, and a machine that has found out audits itself.
     */
    public function test_somebody_elses_lockdown_closes_your_way_in(): void
    {
        $system = $this->machine();
        (new Backdoors)->install($this->character, $system, BackdoorKind::Siphon);

        Lockdown::fromConfig(static fn (int $min, int $max): int => $max)->shut($system);

        $this->assertNull((new Backdoors)->heldOn($this->character->fresh(), $system));
    }

    /** And it takes everybody's, because the machine closed one door and not a list. */
    public function test_a_lockdown_closes_every_door_into_the_machine(): void
    {
        $system = $this->machine();
        $stranger = Character::factory()->create(['archetype' => 3]);

        (new Backdoors)->install($this->character, $system, BackdoorKind::Siphon);
        (new Backdoors)->install($stranger, $system, BackdoorKind::Routing);

        Lockdown::fromConfig(static fn (int $min, int $max): int => $max)->shut($system);

        $this->assertSame(0, Backdoor::query()->count());
    }

    /** A door into a box that has moved is not a door. */
    public function test_relocating_the_machine_closes_it_too(): void
    {
        $system = $this->machine();
        (new Backdoors)->install($this->character, $system, BackdoorKind::Routing);

        RaidLedger::fromConfig(static fn (int $min, int $max): int => $max)->relocate($system);

        $this->assertSame(0, Backdoor::query()->count());
    }

    /** A burn needs no rule of its own: the row goes and the key cascades. */
    public function test_destroying_the_machine_cascades(): void
    {
        $system = $this->machine();
        (new Backdoors)->install($this->character, $system, BackdoorKind::Siphon);

        $system->delete();

        $this->assertSame(0, Backdoor::query()->count());
    }

    // ---- the nightly ageing roll ---------------------------------------------

    /** The odds read AGE and nothing else, so a fresh one is nearly always safe. */
    public function test_a_fresh_backdoor_usually_survives_the_night(): void
    {
        $system = $this->machine();
        (new Backdoors)->install($this->character, $system, BackdoorKind::Siphon);

        // Rolled just above a one-in-a-hundred chance.
        $lost = $this->ageing(rolling: 200)->age();

        $this->assertSame([], $lost);
        $this->assertSame(1, Backdoor::query()->count());
    }

    public function test_an_old_backdoor_is_found_far_more_often(): void
    {
        $system = $this->machine();
        $held = (new Backdoors)->install($this->character, $system, BackdoorKind::Siphon);
        $held->forceFill(['installed_at' => now()->subDays(100)])->save();

        // 1% + 100 days x 0.05 = 6%, and this roll is inside it but outside
        // the 1% a fresh one would have had.
        $lost = $this->ageing(rolling: 550)->age();

        $this->assertCount(1, $lost);
        $this->assertSame(0, Backdoor::query()->count());
    }

    /** The cap holds, however long somebody has sat on one. */
    public function test_the_odds_stop_climbing_at_the_cap(): void
    {
        $system = $this->machine();
        $held = (new Backdoors)->install($this->character, $system, BackdoorKind::Siphon);
        $held->forceFill(['installed_at' => now()->subYears(5)])->save();

        // Just outside a tenth, which is where the table stops.
        $this->assertSame([], $this->ageing(rolling: 1001)->age());
        $this->assertSame(1, Backdoor::query()->count());
    }

    /** Being found moves the machine, which takes every other door with it. */
    public function test_being_found_moves_the_machine(): void
    {
        $system = $this->machine();
        $stranger = Character::factory()->create(['archetype' => 3]);

        (new Backdoors)->install($this->character, $system, BackdoorKind::Siphon)
            ->forceFill(['installed_at' => now()->subDays(100)])->save();
        (new Backdoors)->install($stranger, $system, BackdoorKind::Routing);

        $lost = $this->ageing(rolling: 1)->age();

        $this->assertTrue($lost[0]['moved']);
        $this->assertNotSame('DEADBEEF', $system->fresh()->address);
        $this->assertSame(0, Backdoor::query()->count(), 'and the other door went with it');
    }

    public function test_the_command_rolls_and_reports(): void
    {
        $system = $this->machine();
        (new Backdoors)->install($this->character, $system, BackdoorKind::Siphon);

        $this->artisan('cyberspace:age-backdoors --dry-run')
            ->expectsOutputToContain('1 backdoor(s) would be rolled')
            ->assertSuccessful();

        $this->assertSame(1, Backdoor::query()->count(), 'a dry run wrote nothing');

        $this->artisan('cyberspace:age-backdoors')->assertSuccessful();
    }

    // ---- and what the map says ----------------------------------------------

    /**
     * THE ONE THING ON THE MAP THAT IS NOT MEMORY. A sighting can be wrong; a
     * backdoor is a fact about the player, and they know what they hold.
     */
    public function test_the_map_marks_a_machine_you_hold(): void
    {
        $system = $this->machine();
        (new Backdoors)->install($this->character, $system, BackdoorKind::Routing);

        $cell = $this->sightOfTheMachine();

        $this->assertTrue($cell->hasBackdoor);
        $this->assertStringContainsString('cell--backdoored', $cell->classes());
    }

    public function test_the_map_marks_nothing_you_do_not_hold(): void
    {
        $this->machine();

        $this->assertFalse($this->sightOfTheMachine()->hasBackdoor);
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

    private function begin(
        ?BackdoorKind $kind,
        RunPurpose $purpose = RunPurpose::Backdoor,
        bool $noticed = false,
    ): Run {
        $this->instance(
            CheckRoller::class,
            CheckRoller::fromConfig(static fn (): float => $noticed ? 1.0 : 0.0),
        );

        return app(RunStarter::class)->start($this->character->fresh(), $purpose, $kind)->run;
    }

    private function act(Run $run, RunAction $action): TurnResult
    {
        return $this->simulator()->take($this->character->fresh(), $run->fresh(), $action);
    }

    private function leave(): void
    {
        $this->instance(CheckRoller::class, CheckRoller::fromConfig(static fn (): float => 0.0));

        $this->simulator()->take(
            $this->character->fresh(),
            Run::query()->live()->where('character_id', $this->character->id)->sole(),
            RunAction::Egress,
        );
    }

    private function simulator(): Simulator
    {
        return Simulator::fromConfig(
            static fn (int $min, int $max): int => $max,
            app(Resolver::class),
            app(CheckRoller::class),
        );
    }

    /** The ageing sweep with its percentile die pinned. */
    private function ageing(int $rolling): Backdoors
    {
        return new Backdoors(
            RaidLedger::fromConfig(static fn (int $min, int $max): int => $max),
            static fn (int $min, int $max): int => $rolling,
        );
    }

    /** Seen from next door, since a cell you stand on wears `cell--you`. */
    private function sightOfTheMachine(): MapCell
    {
        $this->character->forceFill(['grid_x' => 7, 'grid_y' => 8])->save();
        $this->character->refresh();

        Sweep::fromConfig()->from($this->character, new Position(7, 8));

        $map = MapView::for($this->character->fresh());

        return $map->rows[$map->range][$map->range + 1];
    }

    /**
     * THE ONE THING THAT IS NOBODY'S FAULT IS ALSO THE ONE NOBODY WAS TOLD
     * ABOUT. It happens in the night, so there is no screen they are about to
     * land on — which is what the inbox exists for. The log line stays beside
     * it for whoever reads the job's output.
     */
    public function test_being_found_reaches_the_inbox(): void
    {
        $system = $this->machine();
        (new Backdoors)->install($this->character, $system, BackdoorKind::Siphon);

        $this->instance(Backdoors::class, $this->ageing(rolling: 1));
        $this->artisan('cyberspace:age-backdoors')->assertSuccessful();

        $message = Notification::query()->sole();

        $this->assertSame($this->character->user_id, $message->user_id);
        $this->assertSame(Category::Cyberspace, $message->category);
        $this->assertStringContainsString($system->displayName(), $message->body);
    }

    /**
     * A STRANGER'S BAD NIGHT IS SOMETHING THAT HAPPENED WHILE NOBODY WAS
     * LOOKING. Every holder hears, once, and in one sentence whatever the cause.
     */
    public function test_losing_a_door_to_a_lockdown_reaches_every_holder(): void
    {
        $system = $this->machine();
        $stranger = Character::factory()->create(['archetype' => 3]);

        (new Backdoors)->install($this->character, $system, BackdoorKind::Siphon);
        (new Backdoors)->install($stranger, $system, BackdoorKind::Routing);

        // The lockdown shuts, and its close relocates: two askers, one message each.
        Lockdown::fromConfig(static fn (int $min, int $max): int => $max)->shut($system);
        RaidLedger::fromConfig(static fn (int $min, int $max): int => $max)->relocate($system->fresh());

        $this->assertEqualsCanonicalizing(
            [$this->character->user_id, $stranger->user_id],
            Notification::query()->pluck('user_id')->all(),
        );
        $this->assertStringContainsString($system->displayName(), Notification::query()->first()->body);
    }

    public function test_a_burn_tells_the_holder_before_the_cascade_would_not(): void
    {
        $system = $this->machine();
        (new Backdoors)->install($this->character, $system, BackdoorKind::Siphon);

        (new Burner)->burn($system);

        $this->assertSame(0, Backdoor::query()->count());
        $this->assertSame($this->character->user_id, Notification::query()->sole()->user_id);
    }

    /** The door that was found is told it was found, and the one beside it that it is gone. */
    public function test_being_found_tells_the_other_holders_once_each(): void
    {
        $system = $this->machine();
        $stranger = Character::factory()->create(['archetype' => 3]);

        (new Backdoors)->install($this->character, $system, BackdoorKind::Siphon)
            ->forceFill(['installed_at' => now()->subDays(100)])->save();
        (new Backdoors)->install($stranger, $system, BackdoorKind::Routing);

        // Only the older door rolls low enough; the stranger's goes with the machine.
        $this->instance(Backdoors::class, $this->ageing(rolling: 550));
        $this->artisan('cyberspace:age-backdoors')->assertSuccessful();

        $this->assertSame(1, Notification::query()->where('user_id', $this->character->user_id)->count());
        $this->assertSame(1, Notification::query()->where('user_id', $stranger->user_id)->count());
    }
}
