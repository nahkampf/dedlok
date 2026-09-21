<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Checks\CheckRoller;
use App\Cyberspace\RunAction;
use App\Cyberspace\RunPurpose;
use App\Cyberspace\RunStarter;
use App\Cyberspace\Simulator;
use App\Models\Character;
use App\Models\Run;
use App\Models\Skill;
use App\Models\System;
use App\Models\SystemContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\Concerns\InjectsSkills;
use Tests\TestCase;

/**
 * Speaking the machine's own operating system, which is the last thing between
 * a run and what it came for.
 *
 * IT IS A ROLL AND NOT A WALL. It used to be a verdict decided at the door —
 * hold the skill at level 1 or every action against the machine fails for ever
 * — which made an unfamiliar system impassable and made levels 2 to 5 worth
 * nothing at all on a run. Now the level IS the check value, the curve runs
 * from under a twentieth to the certainty floor, and a runner who cannot speak
 * it is not refused: they are losing a race against their own trace.
 *
 * The numbers at both ends are the design's, in its own words: level 5 is
 * "more or less 100%, using the uncertainty floor", and no skill at all is
 * "nigh on impossible".
 */
final class OperatingSystemTest extends TestCase
{
    use CarriesAWorkingDeck;
    use InjectsSkills;
    use RefreshDatabase;

    private Character $character;

    private Skill $os;

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
            'grid_x' => 8,
            'grid_y' => 8,
            // A test about the core is not a test about the standing orders.
            'egress_trace_at' => 0,
            'egress_si_at' => 0,
        ])->save();
        $this->character->refresh();

        $this->os = Skill::factory()->create([
            'slug' => 'lattice',
            'title' => 'Lattice',
            'category' => 'Operating Systems',
            'rank' => 4,
        ]);
    }

    // ---- the curve ---------------------------------------------------------

    /**
     * THE WHOLE MECHANIC IN ONE ASSERTION. Both ends are the design's own
     * numbers and the middle is what the logistic makes of them — level 2 is
     * near enough a coin flip, which is where a difficulty is supposed to sit.
     */
    public function test_the_chance_climbs_with_the_level(): void
    {
        $this->machine();
        $run = $this->begin();

        $expected = [0 => 4.74, 1 => 18.06, 2 => 48.13, 3 => 77.07, 4 => 88.97, 5 => 99.15];

        foreach ($expected as $level => $percent) {
            $this->knowsTheOs($level);

            $this->assertEqualsWithDelta(
                $percent / 100,
                app(Simulator::class)->coreChance($this->character->fresh(), $run),
                0.001,
                "level {$level}",
            );
        }
    }

    /** NOT CERTAIN, EVER. The top of the curve is the floor, from the other side. */
    public function test_the_best_runner_alive_can_still_fumble_it(): void
    {
        $this->machine();
        $run = $this->begin();
        $this->knowsTheOs(5);

        $chance = app(Simulator::class)->coreChance($this->character->fresh(), $run);

        $this->assertSame(
            1 - (float) Config::get('checks.certainty_floor'),
            $chance,
            'clamped by the floor rather than reaching one',
        );
    }

    /** A machine running nothing has nothing to get past, and says nothing. */
    public function test_a_machine_running_nothing_asks_for_no_words(): void
    {
        $this->machine(withOs: false);
        $run = $this->begin();

        $this->assertFalse($run->os_locked);
        $this->assertNull(app(Simulator::class)->coreChance($this->character->fresh(), $run));
    }

    /**
     * THE DOOR RECORDS THE MACHINE, NOT THE RUNNER. `os_locked` used to be the
     * runner's verdict; it is the state of the core now, so a machine that
     * speaks something starts shut however well the runner speaks it.
     */
    public function test_the_run_starts_shut_even_for_somebody_fluent(): void
    {
        $this->machine();
        $this->knowsTheOs(5);

        $run = $this->begin();

        $this->assertTrue($run->os_locked);
    }

    // ---- what a turn does with it ------------------------------------------

    public function test_a_landed_roll_opens_the_core_for_the_rest_of_the_run(): void
    {
        $system = $this->machine();
        SystemContent::query()->create(['system_id' => $system->id, 'kind' => 'creds', 'amount' => 500]);

        $run = $this->begin();
        $this->knowsTheOs(3);
        $this->everyCheckPasses();

        $result = app(Simulator::class)->take($this->character->fresh(), $run->fresh(), RunAction::Extract);

        $this->assertFalse($result->run->os_locked, 'open, and it stays open');
        $this->assertSame(500, (int) $this->character->fresh()->creds);
    }

    /**
     * A FAILED ATTEMPT COSTS THE TURN, which is the whole reason no skill at
     * all is nigh on impossible rather than merely slow: the trace is walking
     * while this is being ground at.
     */
    public function test_a_failed_roll_spends_the_turn_and_takes_nothing(): void
    {
        $system = $this->machine();
        SystemContent::query()->create(['system_id' => $system->id, 'kind' => 'creds', 'amount' => 500]);

        $run = $this->begin();
        $this->everyCheckFails();

        $result = app(Simulator::class)->take($this->character->fresh(), $run->fresh(), RunAction::Extract);

        $this->assertTrue($result->run->os_locked, 'still shut');
        $this->assertSame(1, (int) $result->run->turn, 'and the turn is gone');
        $this->assertSame(0, (int) $this->character->fresh()->creds);
        $this->assertFalse((bool) $result->run->extracted);
    }

    /**
     * IT NEVER ANNOUNCES ITSELF. The line a failed core action wears is the
     * one a wrong purpose and an empty machine wear, because naming the
     * operating system would hand over a scan fact the player did not pay for.
     * What they are given instead is the chance, before they spend the turn.
     */
    public function test_the_failure_says_nothing_about_an_operating_system(): void
    {
        $this->machine();
        $run = $this->begin();
        $this->everyCheckFails();

        $result = app(Simulator::class)->take($this->character->fresh(), $run->fresh(), RunAction::Extract);

        foreach (array_column($result->log, 'text') as $line) {
            $this->assertStringNotContainsStringIgnoringCase('operating system', $line);
            $this->assertStringNotContainsStringIgnoringCase('lattice', $line);
        }
    }

    /** And the screen carries the number the roll will use. */
    public function test_the_run_screen_quotes_the_chance(): void
    {
        $this->machine();
        $this->begin();
        $this->knowsTheOs(3);

        $this->actingAs($this->character->user)
            ->get(route('run.show'))
            ->assertOk()
            ->assertSeeText('Core access 77%');
    }

    // ---- fixtures ----------------------------------------------------------

    private function machine(bool $withOs = true): System
    {
        return System::factory()->at(8, 8)->grade(1)->withTracer(2, 4)->create([
            'address' => 'DEADBEEF',
            'os_skill_id' => $withOs ? $this->os->id : null,
        ]);
    }

    /** A run nothing noticed, so nothing here is about a fight. */
    private function begin(): Run
    {
        $this->everyCheckPasses();

        $run = app(RunStarter::class)->start($this->character->fresh(), RunPurpose::Extraction)->run;

        $this->character->refresh();

        return $run;
    }

    private function knowsTheOs(int $level): void
    {
        $this->character->characterSkills()->where('skill_id', $this->os->id)->delete();

        // Level 0 is a real state — injected, untrained — and it reads as
        // zero here exactly as never having owned the book does.
        $this->inject($this->character, $this->os, $this->os->pointsForLevel(max($level, 1)), $level);
    }

    private function everyCheckPasses(): void
    {
        $this->instance(CheckRoller::class, CheckRoller::fromConfig(static fn (): float => 0.0));
    }

    private function everyCheckFails(): void
    {
        $this->instance(CheckRoller::class, CheckRoller::fromConfig(static fn (): float => 1.0));
    }
}
