<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\DefeatTag;
use App\Cyberspace\Defences;
use App\Cyberspace\ScanFact;
use App\Cyberspace\SystemType;
use App\Models\Ice;
use App\Models\System;
use App\Models\SystemScan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * What a machine defends itself with.
 *
 * Type sets the floor and grade rolls the rest, decided once at creation and
 * carried on the row — which is the whole reason a mission node needs no
 * override mechanism. Every roll here is pinned, like every other probabilistic
 * thing in cyberspace.
 */
final class DefencesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A die that always rolls this, CLAMPED TO THE BOUNDS IT IS HANDED.
     *
     * The contract is `random_int`'s — inclusive both ends — and a double that
     * ignores it is not a pinned die, it is a different function. It matters
     * here because `Defences` rolls twice against different ranges: a
     * percentage for presence, and an index to pick a piece of ICE.
     *
     * 1 passes any percentage; 101 clamps to 100 and fails every one.
     */
    private function die(int $value): callable
    {
        return static fn (int $min, int $max): int => min($max, max($min, $value));
    }

    // ---- type sets the floor ----------------------------------------------

    /**
     * THE ROTATION. Each type guarantees two and rolls the third.
     *
     * Asserted with a die that fails every presence roll, so what survives is
     * exactly what the type guarantees and nothing the grade handed out.
     */
    public function test_each_type_always_carries_what_its_row_promises(): void
    {
        $never = $this->die(101);

        $silo = Defences::roll(SystemType::Silo, 5, $never);
        $this->assertGreaterThan(0, $silo->idsDetection, 'a silo always watches');
        $this->assertSame(0, $silo->tracerDetection, 'and rolls for its tracer');

        $relay = Defences::roll(SystemType::Relay, 5, $never);
        $this->assertGreaterThan(0, $relay->idsDetection, 'a relay always watches');
        $this->assertGreaterThan(0, $relay->tracerDetection, 'and always traces');

        // The machine CYBERSPACE.md means by "a system with no IDS but with ICE
        // is a real and ordinary thing". Its ICE is certain and unbuilt; what
        // is left to chance is the thing that would have noticed you.
        $compute = Defences::roll(SystemType::Compute, 5, $never);
        $this->assertSame(0, $compute->idsDetection, 'a compute node rolls for its detection');
        $this->assertGreaterThan(0, $compute->tracerDetection, 'and always traces');
    }

    /** The portal is none of the things a system is, defences included. */
    public function test_the_portal_defends_itself_with_nothing(): void
    {
        $defences = Defences::roll(SystemType::Portal, 9, $this->die(1));

        $this->assertSame(0, $defences->idsDetection);
        $this->assertSame(0, $defences->tracerDetection);
        $this->assertSame(0, $defences->tracerRate);
    }

    // ---- grade rolls the rest ----------------------------------------------

    public function test_a_won_roll_hands_out_the_one_its_type_does_not_guarantee(): void
    {
        $silo = Defences::roll(SystemType::Silo, 9, $this->die(1));

        $this->assertSame(40, $silo->tracerDetection, 'grade 9 tracer detection');
        $this->assertSame(22, $silo->tracerRate, 'and its rate, from the same roll');
    }

    /**
     * A tracer is one thing, so it is rolled for ONCE.
     *
     * Two rolls would give a machine that notices you and traces nowhere half
     * the time it gave one that did neither — a state the CHECK constraint
     * forbids and which would only ever show up on MySQL.
     */
    public function test_a_tracers_two_numbers_are_decided_by_one_roll(): void
    {
        // Alternating: a second roll for the same tracer would disagree.
        $flip = 0;
        $alternating = static function (int $min, int $max) use (&$flip): int {
            $flip++;

            return $flip % 2 === 0 ? 1 : 101;
        };

        for ($i = 0; $i < 6; $i++) {
            $defences = Defences::roll(SystemType::Silo, 5, $alternating);

            $this->assertSame(
                $defences->tracerDetection === 0,
                $defences->tracerRate === 0,
                'both zero or neither',
            );
        }
    }

    public function test_the_bottom_of_the_grid_is_mostly_undefended(): void
    {
        // The grade 1 chance is 10%, so a roll of 11 fails and a roll of 10 does not.
        $this->assertSame(0, Defences::roll(SystemType::Silo, 1, $this->die(11))->tracerDetection);
        $this->assertGreaterThan(0, Defences::roll(SystemType::Silo, 1, $this->die(10))->tracerDetection);
    }

    /** A world whose table has been emptied is harmless, not broken. */
    public function test_an_unconfigured_type_carries_nothing(): void
    {
        Config::set('cyberspace.defences.always', []);

        $defences = Defences::roll(SystemType::Silo, 9, $this->die(101));

        $this->assertSame(0, $defences->idsDetection);
        $this->assertSame(0, $defences->tracerDetection);
    }

    // ---- the mission node --------------------------------------------------

    /**
     * WHY THERE IS NO OVERRIDE MECHANISM.
     *
     * A machine that has to be dangerous is a row with different numbers in the
     * same columns. Nothing derives on read, so nothing recomputes these back
     * to what its grade and type would have produced.
     */
    public function test_a_told_machine_keeps_the_numbers_it_was_given(): void
    {
        $node = System::factory()->at(3, 3)->grade(2)
            ->type(SystemType::Compute)
            ->withIds(92)
            ->withTracer(40, 22)
            ->create();

        $node->refresh();

        $this->assertSame(92, $node->ids_detection, 'a grade 9 watchdog on a grade 2 box');
        $this->assertSame(40, $node->tracer_detection);
        $this->assertTrue($node->hasIds());
        $this->assertTrue($node->hasTracer());
    }

    /** Nothing becomes dangerous by being forgotten. */
    public function test_a_machine_defends_itself_with_nothing_by_default(): void
    {
        $node = System::factory()->at(4, 4)->create();

        $this->assertFalse($node->hasIds());
        $this->assertFalse($node->hasTracer());
    }

    // ---- ICE ---------------------------------------------------------------

    /**
     * ICE IS A POINTER, and the other two defences are numbers.
     *
     * An IDS and a tracer are values on the machine; ICE is a definition with
     * integrity, an attack and a hit chance, so the machine points at a row.
     * `Defences` queries nothing, so the catalogue is handed in — the same
     * shape `Grade::roll()` takes its weights.
     */
    public function test_a_guarded_type_is_given_a_piece_of_ice_from_its_band(): void
    {
        $eligible = Ice::query()->forGrade(5)->pluck('id')->all();

        $this->assertNotEmpty($eligible, 'the catalogue arrives by migration');

        // A silo always fights, whatever the presence roll does.
        $defences = Defences::roll(SystemType::Silo, 5, $this->die(101), $eligible);

        $this->assertContains($defences->iceId, $eligible);
    }

    /** A relay rolls for its ICE, so a lost roll leaves it unguarded. */
    public function test_a_relay_that_loses_its_roll_has_no_ice(): void
    {
        $eligible = Ice::query()->forGrade(5)->pluck('id')->all();

        $this->assertNull(Defences::roll(SystemType::Relay, 5, $this->die(101), $eligible)->iceId);
        $this->assertContains(Defences::roll(SystemType::Relay, 5, $this->die(1), $eligible)->iceId, $eligible);
    }

    /** A world whose ICE has not been migrated is machines that cannot fight, not no world. */
    public function test_an_empty_catalogue_leaves_machines_unguarded(): void
    {
        $this->assertNull(Defences::roll(SystemType::Silo, 5, $this->die(1), [])->iceId);
    }

    /** The bands are the design: nothing from the top of the ladder guards a street-corner box. */
    public function test_the_bands_keep_the_worst_ice_off_the_bottom_of_the_grid(): void
    {
        $lowest = Ice::query()->forGrade(1)->get();
        $highest = Ice::query()->forGrade(9)->get();

        $this->assertNotEmpty($lowest);
        $this->assertNotEmpty($highest);
        $this->assertSame([], array_intersect($lowest->modelKeys(), $highest->modelKeys()));
        $this->assertLessThan($highest->min('integrity'), $lowest->max('integrity'));
        $this->assertLessThan($highest->min('attack'), $lowest->max('attack'));
    }

    /**
     * A MISS DOES NOTHING AT ALL, which is what separates a slow fight from a
     * survivable one — and why skill is its own number rather than folded into
     * attack as an average. An average would make every fight the same fight.
     */
    public function test_skill_is_a_hit_chance_and_spans_the_ladder(): void
    {
        $first = Ice::query()->where('level', 1)->sole();
        $last = Ice::query()->where('level', 12)->sole();

        $this->assertSame(0.15, $first->hitChance());
        $this->assertSame(0.95, $last->hitChance());
    }

    /** Nothing carries a defeat tag yet, and null is the ordinary state for that. */
    public function test_ice_carries_no_defeat_tags_until_somebody_assigns_them(): void
    {
        $this->assertSame([], Ice::query()->where('level', 1)->sole()->defeatTags());
    }

    /** A stored tag comes back as vocabulary, with its rung where it has one. */
    public function test_defeat_tags_read_back_as_tags_and_levels(): void
    {
        $ice = Ice::query()->where('level', 12)->sole();
        $ice->defeat_tags = [
            ['tag' => 'flatline', 'level' => 3],
            ['tag' => 'bounty'],
        ];

        $read = $ice->defeatTags();

        $this->assertSame(DefeatTag::Flatline, $read[0]['tag']);
        $this->assertSame(3, $read[0]['level']);
        $this->assertSame(DefeatTag::Bounty, $read[1]['tag']);
        $this->assertNull($read[1]['level'], 'a bounty has no strength, it just happens');
        $this->assertTrue($ice->carries(DefeatTag::Flatline));
        $this->assertFalse($ice->carries(DefeatTag::Skim));
    }

    /**
     * RETIRED CONTENT STOPS APPEARING; it does not take the page down.
     *
     * These are read on a screen, and a tag the vocabulary no longer knows is
     * the same kind of problem as an item whose type folder was deleted — the
     * answer is to show less, never to throw.
     */
    public function test_a_tag_the_vocabulary_no_longer_knows_is_dropped(): void
    {
        $ice = Ice::query()->where('level', 1)->sole();
        $ice->defeat_tags = [['tag' => 'nonsense'], ['tag' => 'skim', 'level' => 2]];

        $read = $ice->defeatTags();

        $this->assertCount(1, $read);
        $this->assertSame(DefeatTag::Skim, $read[0]['tag']);
    }

    /**
     * SURGE ROLLS PER MODULE, which is the whole of its feel.
     *
     * Two separate 45% rolls usually take one module and sometimes take two or
     * none. One roll for the pair would either cost you both or cost you
     * nothing, which reads as *how likely is a disaster* rather than *how much
     * is at stake* — and the level is meant to say the second thing.
     */
    public function test_surge_raises_what_is_at_stake_before_it_raises_the_odds(): void
    {
        $one = DefeatTag::Surge->levelValue(1);
        $two = DefeatTag::Surge->levelValue(2);
        $three = DefeatTag::Surge->levelValue(3);

        $this->assertSame($one['chance'], $two['chance'], 'II is the same odds against more modules');
        $this->assertGreaterThan($two['modules'], $three['modules']);
        $this->assertGreaterThan($two['chance'], $three['chance'], 'and the top rung moves both');

        // What each level actually costs, in modules, on average.
        $expected = static fn (array $l): float => $l['chance'] / 100 * $l['modules'];

        $this->assertEqualsWithDelta(0.45, $expected($one), 0.001);
        $this->assertEqualsWithDelta(0.90, $expected($two), 0.001);
        $this->assertEqualsWithDelta(1.65, $expected($three), 0.001);
    }

    /** Five tags come in strengths; the one that just happens does not. */
    public function test_the_levelled_tags_have_their_numbers(): void
    {
        $this->assertSame(3, DefeatTag::Flatline->levels());
        $this->assertSame(3, DefeatTag::Skim->levels());
        $this->assertSame(3, DefeatTag::Erase->levels());
        $this->assertSame(3, DefeatTag::NeuralShock->levels());
        $this->assertSame(3, DefeatTag::Surge->levels(), 'and Surge has its numbers now');

        $this->assertSame(0, DefeatTag::Bounty->levels(), 'a bounty is one thing that happens');
        $this->assertCount(6, DefeatTag::cases(), 'six tags — Lobotomy was dropped, not shelved');
        $this->assertSame(['share' => 60], DefeatTag::Skim->levelValue(3));
        $this->assertSame(['count' => 1, 'share' => null], DefeatTag::Erase->levelValue(1));
        $this->assertSame(['chance' => 45, 'modules' => 1], DefeatTag::Surge->levelValue(1));
        $this->assertNull(DefeatTag::Surge->levelValue(4), 'a level nobody defined resolves to nothing');
    }

    // ---- the back-fill -----------------------------------------------------

    /**
     * THE WORLD PREDATES ITS OWN DEFENCES, so somebody has to arm it.
     *
     * The columns default to 0 — *has none* — which is right for a row somebody
     * inserted by hand and wrong for a world. `Defences::roll()` runs when a
     * machine is CREATED, and an existing machine is never created again:
     * relocation keeps the row. Without this pass, a grid generated before
     * defences existed stays defenceless for ever.
     */
    public function test_the_back_fill_arms_machines_that_were_generated_without_defences(): void
    {
        System::factory()->at(1, 1)->type(SystemType::Silo)->grade(5)->create();
        System::factory()->at(2, 2)->type(SystemType::Relay)->grade(5)->create();
        System::factory()->at(3, 3)->portal()->create();

        $this->artisan('cyberspace:defend')->assertSuccessful();

        $this->assertTrue(System::query()->where('type', SystemType::Silo)->sole()->hasIds());
        $this->assertTrue(System::query()->where('type', SystemType::Relay)->sole()->hasTracer());

        $portal = System::query()->where('type', SystemType::Portal)->sole();
        $this->assertFalse($portal->hasIds(), 'the portal is none of the things a system is');
        $this->assertFalse($portal->hasTracer());
    }

    /**
     * A BACK-FILL, NOT A RE-ROLL.
     *
     * A machine that was TOLD what to carry is the case the stored-not-derived
     * design exists to allow — a mission node with a grade 9 watchdog on a
     * grade 2 box — and a pass that overwrote it would destroy the thing it
     * was written to protect.
     */
    public function test_the_back_fill_leaves_a_machine_that_was_told_what_to_carry(): void
    {
        System::factory()->at(4, 4)->type(SystemType::Compute)->grade(2)
            ->withIds(92)->withTracer(40, 22)->create();

        $this->artisan('cyberspace:defend')->assertSuccessful();

        $node = System::query()->sole();
        $this->assertSame(92, $node->ids_detection, 'a told machine keeps what it was told');
        $this->assertSame(40, $node->tracer_detection);
        $this->assertSame(22, $node->tracer_rate);
    }

    public function test_the_back_fill_writes_nothing_on_a_dry_run(): void
    {
        System::factory()->at(5, 5)->type(SystemType::Silo)->grade(9)->create();

        $this->artisan('cyberspace:defend --dry-run')->assertSuccessful();

        $this->assertFalse(System::query()->sole()->hasIds(), 'a dry run rolls and writes nothing');
    }

    // ---- what a scan makes of them ----------------------------------------

    /**
     * ZERO IS AN ANSWER, NOT AN ABSENCE.
     *
     * "There is no intrusion detection here" is most of what somebody scans a
     * machine to hear, and the file has to be able to say it — otherwise the
     * probe would go back round rolling for a fact it already has.
     */
    public function test_a_clean_machine_reads_as_clean_rather_than_as_unread(): void
    {
        $scan = new SystemScan(['ids_detection' => 0, 'tracer_detection' => 0, 'tracer_rate' => 0]);

        $this->assertTrue(ScanFact::Ids->isKnownOn($scan));
        $this->assertTrue(ScanFact::Tracer->isKnownOn($scan));
        $this->assertSame('None', ScanFact::Ids->readFrom($scan));
        $this->assertNotContains('ids', $scan->unknowns());
    }

    public function test_an_unread_machine_is_not_a_clean_one(): void
    {
        $scan = new SystemScan;

        $this->assertFalse(ScanFact::Ids->isKnownOn($scan));
        $this->assertNull(ScanFact::Ids->readFrom($scan));
        $this->assertContains('ids', $scan->unknowns());
    }

    public function test_a_tracer_reads_as_both_halves_of_one_fact(): void
    {
        $scan = new SystemScan(['tracer_detection' => 40, 'tracer_rate' => 22]);

        $this->assertSame('40, tracing 22% a turn', ScanFact::Tracer->readFrom($scan));
    }

    /**
     * All three countermeasures are readable; the cloaked extras are not.
     *
     * `ScanFact` lists what nobody can read yet rather than hiding it — the
     * same decision the item card takes with its unbuilt actions — so this is
     * the check that the list shrinks as things land.
     */
    public function test_every_countermeasure_is_readable_and_cloaking_is_not(): void
    {
        $this->assertTrue(ScanFact::Ids->isDiscoverable());
        $this->assertTrue(ScanFact::Tracer->isDiscoverable());
        $this->assertTrue(ScanFact::Ice->isDiscoverable());
        $this->assertFalse(ScanFact::Cloaked->isDiscoverable(), 'nothing cloaks yet');
    }

    /** A machine with no guard reads as having none, which is a finding. */
    public function test_a_machine_with_no_ice_reads_as_none(): void
    {
        $scan = new SystemScan(['ice_level' => 0]);

        $this->assertTrue(ScanFact::Ice->isKnownOn($scan));
        $this->assertSame('None', ScanFact::Ice->readFrom($scan));
    }

    /** And a guarded one reads by NAME, resolved from the level it remembered. */
    public function test_ice_reads_by_name_from_the_level_it_remembered(): void
    {
        $scan = new SystemScan(['ice_level' => 7]);

        $this->assertSame('ICE VII', ScanFact::Ice->readFrom($scan));
    }
}
