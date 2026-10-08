<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Achievements\Achievements;
use App\Achievements\Metric;
use App\Checks\CheckRoller;
use App\Cyberspace\Navigator;
use App\Cyberspace\Position;
use App\Cyberspace\Probe;
use App\Cyberspace\ProbeRefusal;
use App\Cyberspace\ProbeResult;
use App\Cyberspace\ScanFact;
use App\Cyberspace\Sweep;
use App\Inventory\SkillInjector;
use App\Models\Character;
use App\Models\CharacterModifier;
use App\Models\Ice;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Notification;
use App\Models\Skill;
use App\Models\System;
use App\Models\SystemScan;
use App\Notifications\Category;
use App\Programs\ProgramKind;
use App\Programs\ProgramRunner;
use App\Stats\Resolver;
use App\Stats\Sheet;
use App\Stats\SkillStats;
use App\Support\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/**
 * Finding out what a machine is.
 *
 * The first thing in the game that rolls a check, and the answer to what a
 * grade is for: the difference between a target and an ending is a number you
 * are meant to discover before you approach. Every roll here is pinned — see
 * CheckRollerTest for why.
 */
final class ProbeTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 16);
        Config::set('cyberspace.view_range', 2);

        SkillStats::forget();
        Sheet::forgetSkills();

        Skill::factory()->governedBy('perception', 'memory')->create([
            'slug' => 'systemprofiling',
            'title' => 'System Profiling',
            'rank' => 1,
        ]);

        SkillStats::forget();
        Sheet::forgetSkills();

        $this->character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['archetype' => 3],
        ));

        // Equipped BEFORE they are placed, because the deck lock refuses to
        // equip anything while somebody is inside — and standing in cyberspace
        // without a working deck is the state ReadinessWatch exists to end, so
        // a fixture that skipped this would be ejected by its own first
        // request.
        $this->equipReadyDeck($this->character);

        $this->runAScanner();

        $this->character->forceFill(['grid_x' => 8, 'grid_y' => 8])->save();
        $this->character->refresh();
    }

    /**
     * Look around, the way arriving anywhere does.
     *
     * The screen draws a machine from the CONTACT, not from `systems` — the map
     * is a memory — so a test about what the screen says has to have looked at
     * the thing first. Called by the screen tests and by nothing else: the
     * probe itself reads the world rather than the memory of it.
     */
    private function sweep(): void
    {
        Sweep::fromConfig()->from($this->character->fresh(), new Position(8, 8));
    }

    /**
     * A scanner, running on the deck they are carrying.
     *
     * Reading a machine takes software and the software has to be up, so this
     * is part of the fixture rather than part of a single test — the gate has
     * one test of its own below and everything else assumes it is satisfied.
     */
    private function runAScanner(array $modifiers = []): InventoryItem
    {
        $scanner = $this->ownedBy($this->character, Item::factory()->program(
            ProgramKind::Analyser,
            background: true,
            modifiers: $modifiers,
        )->create());

        $result = app(ProgramRunner::class)->start($this->character->fresh(), $scanner);
        $this->assertTrue($result->succeeded(), 'the fixture has to get the scanner running');

        return $scanner->fresh();
    }

    /** A probe whose dice are ours: `$die` below the chance passes. */
    private function probe(float $die): Probe
    {
        return Probe::fromConfig(CheckRoller::fromConfig(static fn (): float => $die));
    }

    /**
     * Start a pass and wait it out.
     *
     * A pass is two acts now — begun, then settled when its clock runs out —
     * so a test about what a scan LEARNS has to travel past the read. Tests
     * about the clock itself do the two halves by hand.
     */
    private function readOnce(float $die): ProbeResult
    {
        $this->probe($die)->scan($this->character->fresh());

        $this->travel(20)->minutes();

        $scan = SystemScan::query()->where('character_id', $this->character->id)->first();

        return $this->probe($die)->settle($this->character->fresh(), $scan)
            ?? ProbeResult::refused(ProbeRefusal::NothingThere);
    }

    /**
     * Let this character run more than one copy of a program at once.
     *
     * Through a modifier on `program_threads`, which is how Multithreading
     * does it — the probe and the runner know nothing about skills.
     */
    private function allowThreads(int $threads): void
    {
        Config::set('effects.threading', [
            'label' => 'Threading',
            'stacking' => 'unique',
            'retired' => false,
            'modifiers' => [
                ['op' => 'add', 'amount' => $threads - 1, 'target' => ['id' => 'program_threads']],
            ],
        ]);

        CharacterModifier::query()->create([
            'character_id' => $this->character->id,
            'definition_key' => 'threading',
        ]);
    }

    private function machine(int $grade = 1, ?Skill $os = null): System
    {
        return System::factory()->at(8, 8)->create([
            'address' => 'DEADBEEF',
            'grade' => $grade,
            'os_skill_id' => $os?->id,
        ]);
    }

    private function anOperatingSystem(): Skill
    {
        return Skill::factory()->create([
            'slug' => 'auroos',
            'title' => 'AuraOS',
            'category' => 'Operating Systems',
            'rank' => 2,
        ]);
    }

    // ---- what it refuses ---------------------------------------------------

    public function test_the_alert_for_new_scan_data_names_the_system_and_its_address(): void
    {
        $this->assertSame(
            'System Silo - Test 001 [DEADBEEF] has new scan data',
            Text::get('cyberspace.scan.new_data', ['system' => 'Silo - Test 001', 'address' => 'DEADBEEF']),
        );
    }

    public function test_you_cannot_read_a_machine_from_outside_cyberspace(): void
    {
        $this->machine();
        $this->character->forceFill(['grid_x' => null, 'grid_y' => null])->save();

        $result = $this->probe(0.0)->scan($this->character->fresh());

        $this->assertSame(ProbeRefusal::NotJackedIn, $result->refusal);
        $this->assertSame(0, SystemScan::query()->count());
    }

    public function test_there_has_to_be_something_there(): void
    {
        $result = $this->probe(0.0)->scan($this->character);

        $this->assertSame(ProbeRefusal::NothingThere, $result->refusal);
    }

    /** The portal is the one system that is none of the things a system is. */
    public function test_the_portal_answers_nothing(): void
    {
        System::factory()->at(8, 8)->portal()->create(['address' => 'AAAAAAAA']);

        $result = $this->probe(0.0)->scan($this->character);

        $this->assertSame(ProbeRefusal::NotScannable, $result->refusal);
    }

    /**
     * READING A MACHINE TAKES SOFTWARE. A scanner in the stash is a file; what
     * reads a machine is one that is running, spending the deck's compute the
     * whole time it does.
     */
    public function test_a_scan_needs_a_scanner_running(): void
    {
        $this->machine();

        app(ProgramRunner::class)->stopAll($this->character->fresh(), $this->character->equippedDeck);

        $result = $this->probe(0.0)->scan($this->character->fresh());

        $this->assertSame(ProbeRefusal::NoAnalyser, $result->refusal);
        $this->assertSame(0, SystemScan::query()->count());
    }

    /** Owning one is not running one. */
    public function test_a_scanner_sitting_in_the_stash_reads_nothing(): void
    {
        $this->machine();

        app(ProgramRunner::class)->stopAll($this->character->fresh(), $this->character->equippedDeck);
        $this->ownedBy($this->character, Item::factory()->program(ProgramKind::Analyser, background: true)->create());

        $this->assertSame(ProbeRefusal::NoAnalyser, $this->probe(0.0)->scan($this->character->fresh())->refusal);
    }

    // ---- what a better scanner buys ----------------------------------------

    /**
     * A VERSION IS WORTH WHAT ITS ROW SAYS. Neither bonus is known to the
     * probe: one reaches it through the resolver and the other through a stat.
     */
    public function test_a_better_scanner_reads_more_steadily(): void
    {
        $plain = Probe::fromConfig()->chanceFor($this->character->fresh(), new System(['grade' => 4]));

        app(ProgramRunner::class)->stopAll($this->character->fresh(), $this->character->equippedDeck);
        $this->runAScanner([
            ['op' => 'percentage_of_base', 'amount' => 50, 'target' => ['id' => SkillStats::checkId('systemprofiling')]],
        ]);

        $better = Probe::fromConfig()->chanceFor($this->character->fresh(), new System(['grade' => 4]));

        $this->assertGreaterThan($plain, $better);
    }

    // ---- reading it --------------------------------------------------------

    public function test_a_good_pass_finds_out_everything_there_is(): void
    {
        $os = $this->anOperatingSystem();
        $this->machine(grade: 1, os: $os);

        $result = $this->readOnce(0.0);

        $this->assertTrue($result->succeeded());
        $this->assertSame(['grade', 'os', 'ids', 'ice', 'tracer'], $result->found);
        $this->assertTrue($result->scan->isComplete());
        $this->assertSame(1, $result->scan->grade);
        $this->assertSame($os->id, $result->scan->os_skill_id);
        $this->assertSame(1, $result->scan->passes);

        // A system read all the way through is one counted towards the
        // scanning achievements — the pass that COMPLETED the picture.
        $this->assertSame(1, (new Achievements)->progressFor($this->character, Metric::SystemsScanned));
    }

    public function test_a_finished_read_files_itself_and_stays_on_the_map(): void
    {
        $this->machine(grade: 1, os: $this->anOperatingSystem());

        $result = $this->readOnce(0.0);

        $this->assertNotNull($result->scan->fresh()->filed_at);
        $this->assertNull($result->scan->fresh()->used_at, 'filing no longer spends the read');
        $this->assertSame(1, $this->character->inventory()->whereNotNull('scan_file')->count());
    }

    /**
     * Learning nothing is an ordinary outcome, and it still costs a pass.
     *
     * And the instrument goes straight round again: one press is one READ, so
     * the pass that learned nothing is followed by the next one rather than by
     * a screen waiting to be pressed.
     */
    public function test_a_bad_pass_learns_nothing_and_spends_a_pass(): void
    {
        $this->machine(grade: 9);

        $result = $this->readOnce(0.999);

        $this->assertTrue($result->succeeded());
        $this->assertFalse($result->foundSomething());
        $this->assertNull($result->scan->grade);
        $this->assertSame(2, $result->scan->passes, 'the second pass has already begun');
        $this->assertTrue($result->scan->isRunning());
    }

    /**
     * ONE PRESS IS ONE READ.
     *
     * The instrument keeps going until the file is whole, spending a pass each
     * time round. Nothing is pressed after the first Scan and nothing is
     * refunded — what the loop removes is the pressing, not the cost.
     */
    public function test_one_press_reads_until_the_picture_is_whole(): void
    {
        $os = $this->anOperatingSystem();
        $this->machine(grade: 5, os: $os);

        // Every roll fails, so the read goes round for as long as it is
        // allowed — and ONE look is enough to resolve all of it, because the
        // read catches up with the clock rather than advancing a pass per
        // visit. A grade 5 pass is six minutes and the budget is five.
        $this->probe(0.999)->scan($this->character->fresh());

        $this->travel(90)->minutes();
        $this->probe(0.999)->settleAll($this->character->fresh());

        $scan = SystemScan::query()->sole();
        $this->assertSame(16, $scan->passes, 'fifteen matured and a sixteenth already going');
        $this->assertTrue($scan->isRunning(), 'and it is still going, because the file is still good');
    }

    /**
     * A read that answers stops, rather than spending what is left of the
     * budget on a machine it has nothing more to learn from.
     */
    public function test_a_read_that_answers_stops_the_loop(): void
    {
        $os = $this->anOperatingSystem();
        $this->machine(grade: 5, os: $os);

        $this->probe(0.0)->scan($this->character->fresh());

        $this->travel(90)->minutes();
        $this->probe(0.0)->settleAll($this->character->fresh());

        $scan = SystemScan::query()->sole();
        $this->assertTrue($scan->isComplete());
        $this->assertFalse($scan->isRunning());
        $this->assertSame(1, $scan->passes, 'one pass answered it, so one pass is all it cost');
        $this->assertNull($scan->reading_process_id);
    }

    /**
     * Unloading the scanner ends the read, and it ends the LOOP with it.
     *
     * Stopping the program is the one thing a player chose, so nothing starts
     * another pass on their behalf afterwards.
     */
    public function test_stopping_the_scanner_stops_the_read_going_round(): void
    {
        $this->machine(grade: 9);

        $this->probe(0.999)->scan($this->character->fresh());

        app(ProgramRunner::class)->stopAll($this->character->fresh(), $this->character->equippedDeck);

        $this->travel(20)->minutes();
        $this->probe(0.999)->settleAll($this->character->fresh());

        $scan = SystemScan::query()->sole();
        $this->assertSame(1, $scan->passes);
        $this->assertFalse($scan->isRunning());
        $this->assertNull($scan->reading_process_id);
    }

    /** A second pass adds to the file rather than starting a second one. */
    public function test_passes_accumulate_into_one_file(): void
    {
        $os = $this->anOperatingSystem();
        $this->machine(grade: 1, os: $os);

        // A grade 1 pass is about a minute, so the first look catches up the
        // whole budget of failed passes — into ONE file, which is the thing
        // being asserted here.
        $this->readOnce(0.999);

        $this->assertSame(1, SystemScan::query()->count());

        $scan = SystemScan::query()->sole();
        $this->assertSame(21, $scan->passes, 'every pass landed in the same file');
        $this->assertFalse($scan->isComplete(), 'and none of them read anything');

        // Half a picture is not a scanned system, however many passes it ate.
        $this->assertSame(0, (new Achievements)->progressFor($this->character, Metric::SystemsScanned));
    }

    /**
     * THE WINDOW IS WHAT STOPS A FREE ACT BEING GROUND. A failed scan costs
     * the expiry window and an analyser's compute for the length of it, and a
     * read that reaches the end of the window stops there rather than filling
     * a file `fileFor()` is about to throw away.
     */
    public function test_the_read_stops_when_the_file_goes_stale(): void
    {
        Config::set('cyberspace.scan.lifetime_hours', 1);
        $this->machine(grade: 9);

        // Sixteen minutes a pass against an hour of window: four passes begin
        // inside it and the fourth is the last, because the fifth would start
        // after the file stopped being worth anything.
        $probe = $this->probe(0.999);
        $probe->scan($this->character->fresh());

        $this->travel(2)->hours();
        $probe->settleAll($this->character->fresh());

        $scan = SystemScan::query()->sole();

        $this->assertSame(4, $scan->passes);
        $this->assertFalse($scan->isComplete());
        $this->assertFalse($scan->isRunning());
        $this->assertNull($scan->reading_process_id, 'the instrument is free again');
        $this->assertTrue($scan->hasExpired());
    }

    public function test_nothing_is_read_twice(): void
    {
        $this->machine(grade: 1, os: $this->anOperatingSystem());

        $this->readOnce(0.0);
        $result = $this->probe(0.0)->scan($this->character->fresh());

        $this->assertSame(ProbeRefusal::AlreadyKnown, $result->refusal);
    }

    // ---- what makes it hard ------------------------------------------------

    /** The difficulty is the MACHINE's. A grade 9 is hard to read whether or
     *  not anybody knows it is one. */
    public function test_a_harder_machine_is_harder_to_read(): void
    {
        $easy = Probe::fromConfig()->chanceFor($this->character, new System(['grade' => 1]));
        $hard = Probe::fromConfig()->chanceFor($this->character, new System(['grade' => 9]));

        $this->assertGreaterThan($hard, $easy);
        $this->assertGreaterThan(0.5, $easy, 'a fresh character reads the bottom of the grid');
        $this->assertLessThan(0.02, $hard, 'and not the top of it');
    }

    // ---- the screen --------------------------------------------------------

    /** One press is one pass, and the map says what came of it. */
    public function test_the_map_can_read_the_machine_underfoot(): void
    {
        $os = $this->anOperatingSystem();
        $this->machine(grade: 1, os: $os);

        $this->actingAs($this->character->user)
            ->from(route('cyberspace.index'))
            ->post(route('cyberspace.scan'))
            ->assertRedirect(route('cyberspace.index'))
            ->assertSessionHas('status');

        $scan = SystemScan::query()->sole();
        $this->assertSame(1, $scan->passes);
    }

    public function test_the_screen_shows_what_is_known_and_what_is_not(): void
    {
        $os = $this->anOperatingSystem();
        $this->machine(grade: 4, os: $os);

        $this->sweep();

        $blind = $this->actingAs($this->character->user)->get(route('cyberspace.index'));
        $blind->assertSee(Text::get('cyberspace.scan.unknown'));
        $blind->assertDontSee('AuraOS');

        $this->readOnce(0.0);

        $read = $this->actingAs($this->character->fresh()->user)->get(route('cyberspace.index'));
        $read->assertSee(Text::get('cyberspace.scan.grade').' 4', false);
        $read->assertSee('AuraOS');
    }

    /** A refusal says so rather than looking like nothing happened. */
    public function test_scanning_empty_ground_says_why(): void
    {
        $this->actingAs($this->character->user)
            ->from(route('cyberspace.index'))
            ->post(route('cyberspace.scan'))
            ->assertRedirect(route('cyberspace.index'))
            ->assertSessionHas('error', ProbeRefusal::NothingThere->message());
    }

    // ---- a pass takes time -------------------------------------------------

    /**
     * THE CURVE THE DESIGN ASKS FOR: about a minute at the bottom of the grid,
     * about sixteen at the top, for somebody with no skill at all.
     */
    public function test_a_pass_takes_longer_against_a_harder_machine(): void
    {
        $probe = Probe::fromConfig();
        $character = $this->character->fresh();

        $this->assertSame(60, $probe->durationFor($character, new System(['grade' => 1])));
        $this->assertSame(120, $probe->durationFor($character, new System(['grade' => 2])));
        $this->assertSame(16 * 60, $probe->durationFor($character, new System(['grade' => 9])));
    }

    /** Skill and software cut it, through the stat and nothing else. */
    public function test_a_better_instrument_reads_faster(): void
    {
        $slow = Probe::fromConfig()->durationFor($this->character->fresh(), new System(['grade' => 9]));

        app(ProgramRunner::class)->stopAll($this->character->fresh(), $this->character->equippedDeck);
        $this->runAScanner([
            ['op' => 'percentage_of_base', 'amount' => -50, 'target' => ['id' => 'scan_duration']],
        ]);

        $fast = Probe::fromConfig()->durationFor($this->character->fresh(), new System(['grade' => 9]));

        $this->assertSame((int) ($slow / 2), $fast);
    }

    public function test_starting_a_pass_finds_nothing_yet(): void
    {
        $this->machine(grade: 4, os: $this->anOperatingSystem());

        $result = $this->probe(0.0)->scan($this->character);

        $this->assertTrue($result->succeeded());
        $this->assertTrue($result->isRunning());
        $this->assertSame([], $result->found, 'nothing is decided in advance');
        $this->assertNull($result->scan->grade);
        $this->assertSame(1, $result->scan->passes, 'and the pass is spent on starting');
        $this->assertEqualsWithDelta(4 * 60, abs($result->scan->pass_ready_at->diffInSeconds(now())), 2);
    }

    /** The instrument does one thing at a time. */
    public function test_a_second_pass_cannot_start_while_one_is_running(): void
    {
        $this->machine(grade: 4);

        $this->probe(0.0)->scan($this->character);
        $this->travel(1)->minutes();

        $result = $this->probe(0.0)->scan($this->character->fresh());

        $this->assertSame(ProbeRefusal::Busy, $result->refusal);
        $this->assertSame(1, SystemScan::query()->sole()->passes, 'and nothing was spent on the attempt');
    }

    /** Coming back is what settles it — nothing had to be running meanwhile. */
    public function test_the_map_settles_a_pass_that_matured_while_they_were_away(): void
    {
        $this->machine(grade: 1, os: $this->anOperatingSystem());
        $this->sweep();

        // The SCREEN settles it, so the screen's probe is the one that has to
        // roll our dice. It comes from the container for exactly this.
        $this->instance(Probe::class, $this->probe(0.0));

        $this->probe(0.0)->scan($this->character);
        $this->travel(5)->minutes();

        $this->actingAs($this->character->fresh()->user)->get(route('cyberspace.index'))->assertOk();

        $scan = SystemScan::query()->sole();
        $this->assertTrue($scan->isComplete(), 'the answer was waiting on the way in');
        $this->assertNull($scan->pass_ready_at, 'and the clock is cleared');
    }

    /**
     * STOPPING THE SCANNER LOSES THE READ. A read is minutes of an instrument
     * pointed at a machine, not a button that pays out later — so unloading it
     * halfway through costs what it had and the pass with it.
     */
    public function test_stopping_the_scanner_mid_read_voids_the_pass(): void
    {
        $this->machine(grade: 4, os: $this->anOperatingSystem());

        $this->probe(0.0)->scan($this->character);

        app(ProgramRunner::class)->stopAll($this->character->fresh(), $this->character->equippedDeck);
        $this->travel(20)->minutes();

        $result = $this->probe(0.0)->settle($this->character->fresh(), SystemScan::query()->sole());

        $this->assertNotNull($result);
        $this->assertTrue($result->voided);
        $this->assertFalse($result->foundSomething());

        $scan = SystemScan::query()->sole();
        $this->assertNull($scan->grade, 'a read that was not finished found nothing');
        $this->assertSame(1, $scan->passes, 'and the pass is spent all the same');
        $this->assertNull($scan->pass_ready_at);
    }

    /** The screen says so rather than letting minutes vanish quietly. */
    public function test_the_map_reports_a_lost_read(): void
    {
        $this->machine(grade: 1);
        $this->sweep();

        $this->probe(0.0)->scan($this->character);
        app(ProgramRunner::class)->stopAll($this->character->fresh(), $this->character->equippedDeck);
        $this->travel(20)->minutes();

        $this->actingAs($this->character->fresh()->user)
            ->get(route('cyberspace.index'))
            ->assertOk()
            ->assertSee(Text::plural('cyberspace.scan.lost', 1, ['count' => 1]));
    }

    /**
     * AND TURNING IT BACK ON DOES NOT HEAL IT. `started_at` on the running row
     * is later than the read began, so an instrument that was off for part of
     * it is one that did not finish the read — otherwise a player could free
     * the compute for a quarter of an hour and switch it back on before
     * looking.
     */
    public function test_a_lost_read_is_reported_before_another_is_started(): void
    {
        $this->machine(grade: 1);

        $this->probe(0.0)->scan($this->character);
        app(ProgramRunner::class)->stopAll($this->character->fresh(), $this->character->equippedDeck);
        $this->travel(20)->minutes();

        $this->runAScanner();

        $result = $this->probe(0.0)->scan($this->character->fresh());

        $this->assertTrue($result->voided);
        $this->assertSame(1, SystemScan::query()->sole()->passes, 'no second pass was begun');
    }

    /**
     * A machine that stops answering mid-read costs the pass. The instrument
     * has nothing to report, which is honest rather than a refund.
     */
    public function test_a_machine_that_vanishes_mid_read_spends_the_pass(): void
    {
        $system = $this->machine(grade: 1);

        $this->probe(0.0)->scan($this->character);
        $system->delete();
        $this->travel(5)->minutes();

        $result = $this->probe(0.0)->settle($this->character->fresh(), SystemScan::query()->sole());

        $this->assertNotNull($result);
        $this->assertTrue($result->voided);
        $this->assertFalse($result->foundSomething());
        $this->assertSame(1, SystemScan::query()->sole()->passes);
        $this->assertNull(SystemScan::query()->sole()->pass_ready_at);
    }

    public function test_progress_reads_as_a_fraction_of_the_read(): void
    {
        $this->machine(grade: 4);
        $this->probe(0.0)->scan($this->character);

        $scan = SystemScan::query()->sole();
        $this->assertEqualsWithDelta(0.0, $scan->progress(), 0.01);

        $this->travel(2)->minutes();
        $this->assertEqualsWithDelta(0.5, $scan->progress(), 0.02);

        $this->travel(10)->minutes();
        $this->assertSame(1.0, $scan->progress(), 'and it stops at full');
    }

    // ---- in the background, and more than one at a time ---------------------

    /**
     * A READ RUNS WHILE YOU ARE SOMEWHERE ELSE. Point an instrument at a
     * machine and walk away — or jack out entirely — and the answer is waiting
     * when you come back. The instrument is what finishes it, not the standing
     * around.
     */
    public function test_a_read_finishes_while_its_owner_is_elsewhere(): void
    {
        $this->machine(grade: 1, os: $this->anOperatingSystem());

        $this->probe(0.0)->scan($this->character);

        // Out of cyberspace entirely, which stops nothing: an equipped deck
        // keeps running what it was running.
        Navigator::fromConfig(app(Resolver::class))->jackOut($this->character->fresh());
        $this->travel(20)->minutes();

        $settled = $this->probe(0.0)->settleAll($this->character->fresh());

        $this->assertCount(1, $settled);
        $this->assertTrue(SystemScan::query()->sole()->isComplete());
    }

    /** Two instruments, two machines, at the same time. */
    public function test_two_instances_read_two_machines_at_once(): void
    {
        $this->allowThreads(2);
        $this->machine(grade: 1, os: $this->anOperatingSystem());
        $this->runAScanner();

        // The machine underfoot, then one next door.
        $this->probe(0.0)->scan($this->character->fresh());

        System::factory()->at(8, 9)->create(['address' => 'FEEDFACE', 'grade' => 1]);
        $this->character->forceFill(['grid_x' => 8, 'grid_y' => 9])->save();

        $second = $this->probe(0.0)->scan($this->character->fresh());

        $this->assertTrue($second->succeeded(), 'the second instrument takes the second machine');
        $this->assertSame(2, SystemScan::query()->whereNotNull('pass_ready_at')->count());
        $this->assertSame(
            2,
            SystemScan::query()->distinct()->count('reading_process_id'),
            'and they are different instruments',
        );
    }

    /** One scanner cannot be pointed at two machines. */
    public function test_one_instrument_cannot_read_two_machines(): void
    {
        $this->machine(grade: 1);
        $this->probe(0.0)->scan($this->character);

        System::factory()->at(8, 9)->create(['address' => 'FEEDFACE', 'grade' => 1]);
        $this->character->forceFill(['grid_x' => 8, 'grid_y' => 9])->save();

        $result = $this->probe(0.0)->scan($this->character->fresh());

        $this->assertSame(ProbeRefusal::AllBusy, $result->refusal);
        $this->assertSame(1, SystemScan::query()->count());
    }

    /**
     * A matured read frees its instrument, and starting another read is what
     * notices — nothing has to have been running in between.
     */
    public function test_an_instrument_is_free_again_once_its_read_matures(): void
    {
        $this->machine(grade: 1, os: $this->anOperatingSystem());
        $this->probe(0.0)->scan($this->character);
        $this->travel(20)->minutes();

        System::factory()->at(8, 9)->create(['address' => 'FEEDFACE', 'grade' => 1]);
        $this->character->forceFill(['grid_x' => 8, 'grid_y' => 9])->save();

        $result = $this->probe(0.0)->scan($this->character->fresh());

        $this->assertTrue($result->succeeded(), 'the first read settled and let the scanner go');
        $this->assertTrue($result->isRunning());
    }

    // ---- the screen that watches a read -------------------------------------

    /**
     * WHAT THE INSTRUMENT HAS, WHILE IT IS STILL WORKING. The modal polls this,
     * so it carries everything that screen shows: the target, the tool, how far
     * in, and every question a scan asks — including the ones nothing can
     * answer yet.
     */
    public function test_the_reading_endpoint_describes_a_running_pass(): void
    {
        $os = $this->anOperatingSystem();
        $this->machine(grade: 4, os: $os);

        $this->probe(0.0)->scan($this->character);

        $response = $this->actingAs($this->character->fresh()->user)
            ->getJson(route('cyberspace.reading', ['address' => 'DEADBEEF']))
            ->assertOk();

        $response->assertJson([
            'found' => true,
            'address' => 'DEADBEEF',
            'running' => true,
            'pass' => 1,
            'complete' => false,
        ]);

        $this->assertNotNull($response->json('instrument'), 'the screen says what is doing the reading');
        $this->assertNotNull($response->json('name'));
        $this->assertNotNull($response->json('readyIn'));

        // Every question, answered or not, built or not.
        $facts = collect($response->json('facts'))->keyBy('key');

        $this->assertTrue($facts->has('grade'));
        $this->assertNull($facts['grade']['value'], 'nothing is known until the pass matures');
        $this->assertFalse($facts['cloaked']['discoverable'], 'and some of it nothing can read yet');
    }

    /** It settles on the way past, which is what makes the screen update itself. */
    public function test_the_reading_endpoint_settles_a_matured_pass(): void
    {
        $os = $this->anOperatingSystem();
        $this->machine(grade: 1, os: $os);

        $this->instance(Probe::class, $this->probe(0.0));
        $this->probe(0.0)->scan($this->character);
        $this->travel(20)->minutes();

        $response = $this->actingAs($this->character->fresh()->user)
            ->getJson(route('cyberspace.reading', ['address' => 'DEADBEEF']))
            ->assertOk();

        $facts = collect($response->json('facts'))->keyBy('key');

        $this->assertFalse($response->json('running'), 'the clock has stopped');
        $this->assertTrue($response->json('complete'));
        $this->assertSame('I', $facts['grade']['value'], 'a grade is a rank, so it reads as a numeral');
        $this->assertSame('AuraOS', $facts['os']['value'], 'and the name, not the id');
    }

    /**
     * THE OPERATING SYSTEM IS A FACT THAT IS ALSO A GATE.
     *
     * Knowing a machine runs AuraOS and knowing you cannot operate AuraOS are
     * two different things to have learned, and only the second one decides
     * whether to approach. So the read marks it — and marks nothing else,
     * because nothing else on that screen is a gate.
     */
    public function test_the_read_says_whether_you_can_operate_what_it_found(): void
    {
        $os = $this->anOperatingSystem();
        $this->machine(grade: 1, os: $os);

        $this->instance(Probe::class, $this->probe(0.0));
        $this->probe(0.0)->scan($this->character);
        $this->travel(20)->minutes();

        $facts = collect($this->actingAs($this->character->fresh()->user)
            ->getJson(route('cyberspace.reading', ['address' => 'DEADBEEF']))
            ->assertOk()
            ->json('facts'))->keyBy('key');

        $this->assertSame('no', $facts['os']['mark'], 'untrained, so nothing here would work');
        $this->assertNull($facts['grade']['mark'], 'a grade gates nothing');

        // Injected and trained to level 1, which is the whole of the gate.
        app(SkillInjector::class)->grant($this->character->fresh(), $os, 1);

        $facts = collect($this->actingAs($this->character->fresh()->user)
            ->getJson(route('cyberspace.reading', ['address' => 'DEADBEEF']))
            ->assertOk()
            ->json('facts'))->keyBy('key');

        $this->assertSame('ok', $facts['os']['mark']);
    }

    /**
     * ABANDONING FREES THE INSTRUMENT AND REFUNDS NOTHING.
     *
     * The pass is spent when begun, so a player who could take one back by
     * pressing a button would have a free look at whether a read was going
     * well. What it buys is the scanner, immediately, for another machine.
     */
    public function test_abandoning_a_read_frees_the_scanner_and_keeps_the_pass_spent(): void
    {
        $this->machine(grade: 5, os: $this->anOperatingSystem());

        $begun = $this->probe(0.0)->scan($this->character);
        $this->assertTrue($begun->isRunning());
        $this->assertSame(1, $begun->scan->passes);

        $this->actingAs($this->character->fresh()->user)
            ->delete(route('cyberspace.abandon'), ['address' => 'DEADBEEF'])
            ->assertRedirect();

        $scan = SystemScan::query()->where('address', 'DEADBEEF')->first();

        $this->assertNull($scan->reading_process_id, 'the instrument is free again');
        $this->assertNull($scan->pass_ready_at, 'and the clock has stopped');
        $this->assertSame(1, $scan->passes, 'the pass it had begun stays spent');
        $this->assertNotNull($scan->expires_at, 'and the file survives to be continued');
    }

    /**
     * A READ CATCHES UP. It does not advance one pass per visit.
     *
     * Settling is lazy and nothing is scheduled, which is the design — but a
     * read that resolved exactly one pass however long its owner was away made
     * "one press is one read" false in the only way that matters: it needed
     * five presses to go round, and calling them page loads did not help.
     *
     * This failed at 2 of 5 before the clock was walked forward.
     */
    public function test_a_read_left_alone_catches_up_with_the_clock(): void
    {
        $this->machine(grade: 1, os: $this->anOperatingSystem());

        // Every roll fails, so the read goes round for as long as it is allowed.
        $probe = $this->probe(0.999);
        $this->instance(Probe::class, $probe);
        $probe->scan($this->character);

        // A grade 1 pass is about a minute and the budget is five, so an hour
        // is far more than the whole read could ever need.
        $this->travel(60)->minutes();
        $probe->settleAll($this->character->fresh());

        $scan = SystemScan::query()->where('address', 'DEADBEEF')->first();

        $this->assertSame(61, $scan->passes, 'an hour away is an hour of reading');
        $this->assertTrue($scan->isRunning(), 'and the sixty-first is already going');
    }

    /** Catching up stops at the answer, not at the budget. */
    public function test_catching_up_stops_as_soon_as_the_picture_is_whole(): void
    {
        $this->machine(grade: 1, os: $this->anOperatingSystem());

        $probe = $this->probe(0.0);
        $this->instance(Probe::class, $probe);
        $probe->scan($this->character);

        $this->travel(60)->minutes();
        $probe->settleAll($this->character->fresh());

        $scan = SystemScan::query()->where('address', 'DEADBEEF')->first();

        $this->assertTrue($scan->isComplete());
        $this->assertSame(1, $scan->passes, 'a read that answered everything reads no further');
    }

    // ---- the sweep that settles for people who are not looking ------------

    /**
     * THE ROW IS TRUE BETWEEN VISITS.
     *
     * The sweep is not what makes a read finish — the settling catches up with
     * the clock on its own, which the tests above pin. What it buys is a row
     * that is resolved before anybody asks, which is what every reader that is
     * not the owner needs: a notification can only be raised by something that
     * NOTICED a read finish, and lazy settling never notices.
     */
    public function test_the_sweep_settles_a_read_nobody_looked_at(): void
    {
        $this->machine(grade: 1, os: $this->anOperatingSystem());

        $this->instance(Probe::class, $this->probe(0.999));
        $this->probe(0.999)->scan($this->character);

        // Nobody looks. The row still says a pass is running.
        $this->travel(90)->minutes();
        $this->assertNotNull(SystemScan::query()->sole()->pass_ready_at);

        $this->artisan('cyberspace:settle')->assertSuccessful();

        $scan = SystemScan::query()->sole();
        // Not null but FUTURE: ninety passes resolved without anybody asking
        // and the ninety-first is already going, which is what a read that
        // runs until it has the picture looks like between visits.
        $this->assertTrue($scan->pass_ready_at->isFuture(), 'resolved without anybody asking');
        $this->assertSame(91, $scan->passes, 'and caught the read up rather than nudging it one pass');
    }

    public function test_the_sweep_writes_nothing_on_a_dry_run(): void
    {
        $this->machine(grade: 1, os: $this->anOperatingSystem());

        $this->instance(Probe::class, $this->probe(0.999));
        $this->probe(0.999)->scan($this->character);
        $this->travel(90)->minutes();

        $this->artisan('cyberspace:settle --dry-run')->assertSuccessful();

        $this->assertNotNull(
            SystemScan::query()->sole()->pass_ready_at,
            'a dry run reports and writes nothing',
        );
    }

    /** A sweep with nothing to do is successful and says nothing. */
    public function test_an_empty_sweep_is_quiet(): void
    {
        $this->artisan('cyberspace:settle --quiet-when-idle')
            ->doesntExpectOutput('Nothing had matured.')
            ->assertSuccessful();
    }

    /** Abandoning what is not running is an ordinary answer, not an error. */
    public function test_abandoning_nothing_refuses_quietly(): void
    {
        $this->machine(grade: 5);

        $this->actingAs($this->character->fresh()->user)
            ->delete(route('cyberspace.abandon'), ['address' => 'DEADBEEF'])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    /**
     * The probe writes the bounty with the guard, off the machine it is reading.
     *
     * End to end rather than on a constructed row: what matters is that the
     * scan's copy agrees with the system's, since the file has to outlive the
     * machine and be right about it afterwards.
     */
    public function test_a_read_carries_the_bounty_off_the_machine(): void
    {
        $ice = Ice::query()->where('level', 3)->sole();

        System::query()->where('address', 'DEADBEEF')->delete();
        System::factory()->at(8, 8)->grade(1)->withIce($ice)->create([
            'address' => 'DEADBEEF',
            'ice_bounty' => true,
        ]);

        $result = $this->readOnce(0.0);

        $this->assertTrue($result->scan->ice_bounty, 'the file knows the owner answers');
        $this->assertSame(3, $result->scan->ice_level);
        $this->assertSame('ICE III · Bounty', ScanFact::Ice->readFrom($result->scan));
    }

    /** A fact nobody has read is marked with nothing — an unanswered question is not a failure. */
    public function test_an_unread_operating_system_is_not_marked_as_a_failure(): void
    {
        $this->machine(grade: 9, os: $this->anOperatingSystem());

        $this->instance(Probe::class, $this->probe(0.999));
        $this->probe(0.999)->scan($this->character);

        $facts = collect($this->actingAs($this->character->fresh()->user)
            ->getJson(route('cyberspace.reading', ['address' => 'DEADBEEF']))
            ->assertOk()
            ->json('facts'))->keyBy('key');

        $this->assertNull($facts['os']['value'], 'nothing was found out');
        $this->assertNull($facts['os']['mark'], 'so nothing is claimed about it either');
    }

    /**
     * Pressing Scan opens the screen on the machine just pointed at, rather
     * than leaving a sentence saying a read began somewhere.
     */
    public function test_starting_a_read_opens_the_screen_on_it(): void
    {
        $this->machine(grade: 1);
        $this->sweep();

        $this->actingAs($this->character->user)
            ->from(route('cyberspace.index'))
            ->post(route('cyberspace.scan'))
            ->assertRedirect(route('cyberspace.index'))
            ->assertSessionHas('reading', 'DEADBEEF');

        $this->actingAs($this->character->fresh()->user)
            ->get(route('cyberspace.index'))
            ->assertOk()
            ->assertSee('data-reading-open="DEADBEEF"', false);
    }

    /** And the map keeps a door back into a read still running. */
    public function test_the_map_offers_a_way_back_into_a_running_read(): void
    {
        $this->machine(grade: 4);
        $this->sweep();
        $this->probe(0.0)->scan($this->character);

        $this->actingAs($this->character->fresh()->user)
            ->get(route('cyberspace.index'))
            ->assertOk()
            ->assertSee('data-reading-open="DEADBEEF"', false)
            ->assertSee(Text::get('cyberspace.reading.title'));
    }

    /** A file that has gone is a thing the screen has to be able to say. */
    public function test_the_reading_endpoint_admits_when_there_is_no_file(): void
    {
        $this->actingAs($this->character->fresh()->user)
            ->getJson(route('cyberspace.reading', ['address' => 'NOTHINGX']))
            ->assertOk()
            ->assertJson(['found' => false]);
    }

    /** One player cannot watch another's instrument. */
    public function test_a_reading_belongs_to_whoever_started_it(): void
    {
        $this->machine(grade: 1);
        $this->probe(0.0)->scan($this->character);

        $other = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['archetype' => 3],
        ));

        $this->actingAs($other->user)
            ->getJson(route('cyberspace.reading', ['address' => 'DEADBEEF']))
            ->assertOk()
            ->assertJson(['found' => false]);
    }

    // ---- perishing ---------------------------------------------------------

    public function test_an_expired_file_is_replaced_rather_than_continued(): void
    {
        $os = $this->anOperatingSystem();
        $this->machine(grade: 1, os: $os);

        // A read that finished, on a file that has since gone stale — which is
        // the state this is about, and it has to be reached the way a player
        // would reach it rather than written straight into the row.
        $this->readOnce(0.999);

        SystemScan::query()->sole()->forceFill([
            'expires_at' => now()->subHour(),
            'passes' => 5,
        ])->save();

        $result = $this->probe(0.0)->scan($this->character->fresh());

        $this->assertTrue($result->succeeded(), 'a stale file is not an exhausted one');
        $this->assertSame(1, $result->scan->passes, 'the count started again');
        $this->assertSame(1, SystemScan::query()->count());
    }

    public function test_a_file_knows_when_it_goes_stale(): void
    {
        Config::set('cyberspace.scan.lifetime_hours', 48);
        $this->machine();

        $scan = $this->probe(0.0)->scan($this->character)->scan;

        $this->assertEqualsWithDelta(48 * 3600, $scan->expires_at->diffInSeconds($scan->scanned_at, true), 5);
        $this->assertFalse($scan->hasExpired());
        $this->assertFalse($scan->isSpent());
    }

    /** Live means unspent and unexpired, which is the listing every screen wants. */
    public function test_the_live_scope_leaves_out_the_spent_and_the_stale(): void
    {
        $this->machine();
        $this->probe(0.0)->scan($this->character);

        $this->assertSame(1, SystemScan::query()->live()->count());

        SystemScan::query()->sole()->forceFill(['used_at' => now()])->save();
        $this->assertSame(0, SystemScan::query()->live()->count());

        SystemScan::query()->sole()->forceFill(['used_at' => null, 'expires_at' => now()->subMinute()])->save();
        $this->assertSame(0, SystemScan::query()->live()->count());
    }

    // ---- the mark it leaves on the map -------------------------------------

    /**
     * A MACHINE YOU HAVE READ SOMETHING OUT OF IS MARKED.
     *
     * The map is where a read pays off, so a file with anything in it puts a
     * tick on the cell — the same device as the backdoor and the bookmark, in
     * the last free corner.
     *
     * Counted rather than merely seen, because the key carries a sample swatch
     * of its own and is supposed to.
     */
    public function test_the_map_marks_a_machine_something_was_read_out_of(): void
    {
        $this->machine(grade: 1, os: $this->anOperatingSystem());

        $this->instance(Probe::class, $this->probe(0.0));
        $this->probe(0.0)->scan($this->character);
        $this->travel(20)->minutes();
        $this->sweep();

        $html = $this->actingAs($this->character->fresh()->user)
            ->get(route('cyberspace.index'))
            ->assertOk()
            ->getContent();

        $this->assertSame(2, substr_count($html, 'cell--scanned'), 'the key, and one read machine');
    }

    /** Pointing an instrument at something is not learning anything about it. */
    public function test_a_read_that_learned_nothing_marks_nothing(): void
    {
        $this->machine(grade: 9);

        $this->instance(Probe::class, $this->probe(0.999));
        $this->probe(0.999)->scan($this->character);
        $this->travel(20)->minutes();
        $this->sweep();

        $html = $this->actingAs($this->character->fresh()->user)
            ->get(route('cyberspace.index'))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($html, 'cell--scanned'), 'the key alone');
    }

    /**
     * AND IT CLEARS ITSELF WHEN THE MACHINE MOVES.
     *
     * A relocated system comes back wearing a new address, and the file
     * describes the old one — so the mark goes without anything having to
     * remove it. The same property that keeps a stale grade off the map.
     */
    public function test_the_mark_goes_when_the_machine_relocates(): void
    {
        $this->machine(grade: 1, os: $this->anOperatingSystem());

        $this->instance(Probe::class, $this->probe(0.0));
        $this->probe(0.0)->scan($this->character);
        $this->travel(20)->minutes();
        $this->sweep();

        System::query()->sole()->forceFill(['address' => 'FEEDFACE'])->save();
        $this->sweep();

        $html = $this->actingAs($this->character->fresh()->user)
            ->get(route('cyberspace.index'))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($html, 'cell--scanned'), 'the key alone');
    }

    /**
     * THE READ IS WHAT THE INBOX WAS BUILT FOR. A pass takes minutes and a
     * read takes as many as it needs, so whoever pressed Scan is usually
     * somewhere else by the time the file is whole — which is the definition
     * of something that happened while nobody was looking.
     */
    public function test_a_finished_read_is_a_message(): void
    {
        $this->machine(grade: 1, os: $this->anOperatingSystem());

        $this->readOnce(0.0);

        $message = Notification::query()->sole();

        $this->assertSame(Category::Cyberspace, $message->category);

        // Through the config rather than against a phrase. The sentence is
        // rendered once at write time and stored, so what this checks is that
        // the right key was rendered against the right machine — not the
        // wording, which is `strings.php`'s to change.
        $this->assertSame(
            Text::get('notifications.messages.scan_complete', [
                'system' => System::query()->sole()->displayName(),
                'address' => System::query()->sole()->address,
            ]),
            $message->body,
        );
        $this->assertSame(System::query()->sole()->address, $message->details['address'], 'and it rides along for the copy button');
    }

    /** A pass that learned nothing has nothing to say. */
    public function test_an_unfinished_read_says_nothing(): void
    {
        $this->machine(grade: 9);

        $this->readOnce(0.999);

        $this->assertSame(0, Notification::query()->count());
    }
}
