<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Checks\CheckRoller;
use App\Cyberspace\Position;
use App\Cyberspace\Probe;
use App\Cyberspace\ProbeRefusal;
use App\Cyberspace\Sweep;
use App\Models\Character;
use App\Models\Skill;
use App\Models\System;
use App\Models\SystemScan;
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

        $this->character->forceFill(['grid_x' => 8, 'grid_y' => 8])->save();
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

    /** A probe whose dice are ours: `$die` below the chance passes. */
    private function probe(float $die): Probe
    {
        return Probe::fromConfig(CheckRoller::fromConfig(static fn (): float => $die));
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

    // ---- reading it --------------------------------------------------------

    public function test_a_good_pass_finds_out_everything_there_is(): void
    {
        $os = $this->anOperatingSystem();
        $this->machine(grade: 1, os: $os);

        $result = $this->probe(0.0)->scan($this->character);

        $this->assertTrue($result->succeeded());
        $this->assertSame(['grade', 'os'], $result->found);
        $this->assertTrue($result->scan->isComplete());
        $this->assertSame(1, $result->scan->grade);
        $this->assertSame($os->id, $result->scan->os_skill_id);
        $this->assertSame(1, $result->scan->passes);
    }

    /** Learning nothing is an ordinary outcome, and it still costs a pass. */
    public function test_a_bad_pass_learns_nothing_and_spends_a_pass(): void
    {
        $this->machine(grade: 9);

        $result = $this->probe(0.999)->scan($this->character);

        $this->assertTrue($result->succeeded());
        $this->assertFalse($result->foundSomething());
        $this->assertNull($result->scan->grade);
        $this->assertSame(1, $result->scan->passes);
    }

    /** A second pass adds to the file rather than starting a second one. */
    public function test_passes_accumulate_into_one_file(): void
    {
        $os = $this->anOperatingSystem();
        $this->machine(grade: 1, os: $os);

        $this->probe(0.999)->scan($this->character);
        $this->probe(0.0)->scan($this->character->fresh());

        $this->assertSame(1, SystemScan::query()->count());

        $scan = SystemScan::query()->sole();
        $this->assertSame(2, $scan->passes);
        $this->assertTrue($scan->isComplete());
    }

    /**
     * THE BUDGET IS WHAT STOPS A FREE ACT BEING GROUND. A failed scan costs the
     * expiry window, not a retry.
     */
    public function test_the_passes_run_out(): void
    {
        Config::set('cyberspace.scan.passes', 2);
        $this->machine(grade: 9);

        $this->probe(0.999)->scan($this->character);
        $this->probe(0.999)->scan($this->character->fresh());

        $result = $this->probe(0.0)->scan($this->character->fresh());

        $this->assertSame(ProbeRefusal::Exhausted, $result->refusal);
        $this->assertSame(2, SystemScan::query()->sole()->passes);
    }

    public function test_nothing_is_read_twice(): void
    {
        $this->machine(grade: 1, os: $this->anOperatingSystem());

        $this->probe(0.0)->scan($this->character);
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

        $this->probe(0.0)->scan($this->character);

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

    // ---- perishing ---------------------------------------------------------

    public function test_an_expired_file_is_replaced_rather_than_continued(): void
    {
        $os = $this->anOperatingSystem();
        $this->machine(grade: 1, os: $os);

        $this->probe(0.999)->scan($this->character);

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
}
