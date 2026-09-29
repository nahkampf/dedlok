<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Checks\CheckRoller;
use App\Cyberspace\AttackTag;
use App\Cyberspace\RunPurpose;
use App\Cyberspace\RunStarter;
use App\Cyberspace\Simulator;
use App\Models\Character;
use App\Models\Ice;
use App\Models\Run;
use App\Models\Skill;
use App\Models\System;
use App\Stats\Resolver;
use App\Support\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\Concerns\InjectsSkills;
use Tests\TestCase;

/**
 * Leaving, and what it costs to be held while you try.
 *
 * Two mechanics that only make sense together: the egress roll — always
 * possible, never certain — and Ensnare, the first attack tag, which takes a
 * share off that chance for as long as the thing carrying it is awake.
 */
final class EnsnareTest extends TestCase
{
    use CarriesAWorkingDeck;
    use InjectsSkills;
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
            'grid_x' => 8,
            'grid_y' => 8,
            'egress_trace_at' => 0,
            'egress_si_at' => 0,
        ])->save();
        $this->character->refresh();
    }

    // ---- the tag itself ----------------------------------------------------

    public function test_the_levels_are_a_quarter_two_fifths_and_rather_more_than_half(): void
    {
        $this->assertSame(0.75, round(AttackTag::Ensnare->multiplier(1), 2));
        $this->assertSame(0.60, round(AttackTag::Ensnare->multiplier(2), 2));
        $this->assertSame(0.45, round(AttackTag::Ensnare->multiplier(3), 2));
    }

    /** A level nobody has priced does nothing, rather than throwing. */
    public function test_an_unpriced_level_multiplies_by_one(): void
    {
        $this->assertSame(1.0, AttackTag::Ensnare->multiplier(9));
    }

    public function test_null_and_an_empty_list_are_one_answer(): void
    {
        $ice = $this->ice(6);

        $ice->forceFill(['attack_tags' => null])->save();
        $this->assertNull($ice->fresh()->attackLevel(AttackTag::Ensnare));

        $ice->forceFill(['attack_tags' => []])->save();
        $this->assertNull($ice->fresh()->attackLevel(AttackTag::Ensnare));
    }

    /** A tag the vocabulary does not know is dropped rather than taking a screen down. */
    public function test_an_unknown_tag_is_ignored(): void
    {
        $ice = $this->ice(6);
        $ice->forceFill(['attack_tags' => [['tag' => 'entangle', 'level' => 2]]])->save();

        $this->assertSame([], $ice->fresh()->attackTags());
    }

    // ---- what it does to the chance ---------------------------------------

    /** A quiet machine is the cheap exit: nothing awake, nothing holding. */
    public function test_an_unguarded_machine_does_not_reduce_the_chance(): void
    {
        $this->machine(1);
        $run = $this->beginUnnoticed();

        $this->assertNull($run->ice_id);
        $this->assertSame(
            $this->chanceAgainst(grade: 1, iceLevel: 0),
            round($this->simulator()->egressChance($this->character->fresh(), $run->fresh()), 4),
        );
    }

    /** A higher grade is harder to walk out of, ICE or no ICE. */
    public function test_a_deeper_machine_is_harder_to_leave(): void
    {
        $shallow = $this->chanceAgainst(grade: 1, iceLevel: 0);
        $deep = $this->chanceAgainst(grade: 9, iceLevel: 0);

        $this->assertGreaterThan($deep, $shallow);
    }

    /**
     * ENSNARE IS A FACTOR ON THE CHANCE, not points off it — the same shape
     * every other percentage in the game has.
     */
    public function test_ensnare_multiplies_the_chance(): void
    {
        $system = $this->machine(6);
        $this->snare($system->ice, 2);

        $run = $this->begin();
        $this->assertNotNull($run->ice_id, 'something noticed and woke up');

        $bare = $this->chanceAgainst(grade: 1, iceLevel: 6);
        $snared = round($this->simulator()->egressChance($this->character->fresh(), $run->fresh()), 4);

        $this->assertSame(round($bare * 0.6, 4), $snared);
    }

    /**
     * ENSNARE'S BITE IS RESOLVED, not a bare number any more —
     * `ensnare_reduction` is the base a modifier reaches. `AttackTag::
     * multiplier()` itself stays pure (see the tests above); this is
     * `Simulator::ensnareMultiplier()`, the one place a character's own
     * modifiers get a say.
     */
    public function test_a_skill_can_blunt_ensnare(): void
    {
        $system = $this->machine(12);
        $this->snare($system->ice, 3);

        $skill = Skill::factory()->rank(1)->governedBy('memory', 'intellect')->create([
            'slug' => 'a_severance_skill',
            'modifiers' => [['op' => 'percentage_of_base', 'level' => 1, 'amount' => -25, 'target' => ['id' => 'ensnare_reduction']]],
        ]);
        $this->inject($this->character, $skill, $skill->pointsForLevel(1), 1);

        $run = $this->begin();

        $bare = $this->chanceAgainst(grade: 1, iceLevel: 12);
        $blunted = round($this->simulator()->egressChance($this->character->fresh(), $run->fresh()), 4);

        // 55% off at Ensnare III, cut by a quarter, is 41.25% off: ×0.5875.
        $this->assertSame(round($bare * 0.5875, 4), $blunted);
    }

    /**
     * IT NEVER CLOSES THE DOOR. A run with no exit is a character deleted by a
     * door they could not see, so the reduced chance is floored again.
     */
    public function test_even_the_worst_snare_leaves_the_door_open(): void
    {
        $system = $this->machine(12);
        $this->snare($system->ice, 3);

        $run = $this->begin();

        $chance = $this->simulator()->egressChance($this->character->fresh(), $run->fresh());

        $this->assertGreaterThanOrEqual(
            app(CheckRoller::class)->floorFor('egress'),
            $chance,
            'the floor holds under the multiplier',
        );
    }

    /**
     * PASSIVE, SO BREAKING THE GUARD IS THE WAY OUT FROM UNDER IT. Nothing is
     * stored on the run, so there is nothing to expire and nothing that can
     * outlive its cause.
     */
    public function test_breaking_the_ice_takes_the_snare_with_it(): void
    {
        $system = $this->machine(6);
        $this->snare($system->ice, 3);

        $run = $this->begin();
        $snared = $this->simulator()->egressChance($this->character->fresh(), $run->fresh());

        // What the fight does to the ICE is the fight's business; this is the
        // state it leaves behind.
        $run->forceFill(['ice_id' => null, 'ice_integrity' => null])->save();

        $free = $this->simulator()->egressChance($this->character->fresh(), $run->fresh());

        $this->assertGreaterThan($snared, $free);
        $this->assertSame($this->chanceAgainst(grade: 1, iceLevel: 0), round($free, 4));
    }

    /** An undetected run never woke the guard, so it is not held by it either. */
    public function test_an_unnoticed_run_is_not_snared(): void
    {
        $system = $this->machine(6);
        $this->snare($system->ice, 3);

        $run = $this->beginUnnoticed();

        $this->assertNull($run->ice_id);
        $this->assertSame(
            $this->chanceAgainst(grade: 1, iceLevel: 0),
            round($this->simulator()->egressChance($this->character->fresh(), $run->fresh()), 4),
        );
    }

    // ---- and what the screen says -----------------------------------------

    /**
     * THE SCREEN QUOTES THE SAME NUMBER THE ROLL USES. That is the whole
     * reason `egressChance()` is public and separate from the roll.
     */
    public function test_the_screen_quotes_the_chance_and_says_when_you_are_held(): void
    {
        $system = $this->machine(6);
        $this->snare($system->ice, 2);

        $run = $this->begin();
        $expected = (int) round($this->simulator()->egressChance($this->character->fresh(), $run->fresh()) * 100);

        $this->actingAs($this->character->user)
            ->get(route('run.show'))
            ->assertOk()
            ->assertSee('Egress '.$expected.'%')
            ->assertSee(Text::get('cyberspace.run.egress_snared'));
    }

    /**
     * THE WORKING IS ON THE SCREEN, in one template the kit clones into the
     * hover layer and the modal both — so the two presentations cannot drift,
     * and a phone (which has no hover at all) still gets the explanation.
     */
    public function test_the_screen_shows_how_the_chance_is_worked_out(): void
    {
        $this->machine(6);
        $run = $this->begin();

        $report = $this->simulator()->egressReport($this->character->fresh(), $run->fresh());

        $this->actingAs($this->character->user)
            ->get(route('run.show'))
            ->assertOk()
            // One template, addressed by the inspect behaviour.
            ->assertSee('data-inspect="#egress-working"', false)
            ->assertSee('id="egress-working"', false)
            // The pool, by its terms — Reflex is the one stealth does not have.
            ->assertSee('Reflex')
            ->assertSee('Focus')
            ->assertSee('Intellect')
            // What it is up against, and what it comes to.
            ->assertSee(Text::get('cyberspace.run.evasion_grade', ['grade' => 1]))
            ->assertSee(Text::get('cyberspace.run.evasion_ice', ['level' => 6]))
            ->assertSee(Text::get('cyberspace.run.evasion_difficulty'))
            ->assertSee((string) $report->difficulty)
            ->assertSee(Text::get('cyberspace.run.evasion_result'));
    }

    /** With nothing carried, the card says so rather than showing an empty list. */
    public function test_an_unmodified_pool_says_so(): void
    {
        $this->machine(1);
        $this->beginUnnoticed();

        $this->actingAs($this->character->user)
            ->get(route('run.show'))
            ->assertOk()
            ->assertSee(Text::get('cyberspace.run.evasion_unmodified'));
    }

    /** Nothing holding, nothing said about being held. */
    public function test_the_screen_says_nothing_about_a_snare_that_is_not_there(): void
    {
        $this->machine(1);
        $this->beginUnnoticed();

        $this->actingAs($this->character->user)
            ->get(route('run.show'))
            ->assertOk()
            ->assertDontSee(Text::get('cyberspace.run.egress_snared'));
    }

    // ---- the other door out ------------------------------------------------

    /**
     * THE STANDALONE ROUTE ROLLS TOO. It used to end a run outright, which was
     * a free exit the moment leaving became a roll — a player who found it
     * could walk out of a fight the Egress button was failing at.
     */
    public function test_the_standalone_egress_route_can_be_held(): void
    {
        // NO ICE ON PURPOSE. A guarded machine would get its half of the turn
        // after the failed attempt and the run could end as a defeat instead,
        // which is a different thing being tested.
        System::factory()->at(8, 8)->grade(1)->withTracer(2, 4)->create(['address' => 'DEADBEEF']);
        $run = $this->begin();

        // begin() pins every check to fail, and that now includes leaving.
        $this->actingAs($this->character->user)->post(route('run.egress'));

        $this->assertTrue($run->fresh()->isLive(), 'still in it');
        $this->assertNotNull($this->character->fresh()->current_run_id);
    }

    public function test_the_standalone_egress_route_still_lets_you_out(): void
    {
        $this->machine(1);
        $run = $this->beginUnnoticed();

        $this->actingAs($this->character->user)->post(route('run.egress'));

        $this->assertFalse($run->fresh()->isLive());
        $this->assertNull($this->character->fresh()->current_run_id);
    }

    // ---- fixtures ----------------------------------------------------------

    private function ice(int $level): Ice
    {
        return Ice::query()->where('level', $level)->sole();
    }

    private function machine(int $iceLevel): System
    {
        return System::factory()->at(8, 8)->grade(1)
            ->withIce($this->ice($iceLevel))
            ->withTracer(2, 4)
            ->create(['address' => 'DEADBEEF']);
    }

    private function snare(Ice $ice, int $level): void
    {
        $ice->forceFill(['attack_tags' => [['tag' => 'ensnare', 'level' => $level]]])->save();
    }

    /** The chance this character would have, worked out independently of the simulator. */
    private function chanceAgainst(int $grade, int $iceLevel): float
    {
        $difficulty = Config::get('cyberspace.egress.difficulty')[$grade]
            + ($iceLevel * Config::get('cyberspace.egress.ice_term'));

        return round(app(CheckRoller::class)->chance(
            $this->character->fresh()->stat('egress')->value,
            (float) $difficulty,
            'egress',
        ), 4);
    }

    private function simulator(): Simulator
    {
        return Simulator::fromConfig(
            static fn (int $min, int $max): int => $max,
            app(Resolver::class),
            app(CheckRoller::class),
        );
    }

    private function begin(): Run
    {
        $this->instance(CheckRoller::class, CheckRoller::fromConfig(static fn (): float => 1.0));

        return app(RunStarter::class)->start($this->character->fresh(), RunPurpose::Extraction)->run;
    }

    private function beginUnnoticed(): Run
    {
        $this->instance(CheckRoller::class, CheckRoller::fromConfig(static fn (): float => 0.0));

        return app(RunStarter::class)->start($this->character->fresh(), RunPurpose::Extraction)->run;
    }
}
