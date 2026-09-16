<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\Defences;
use App\Cyberspace\ScanFact;
use App\Cyberspace\SystemType;
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

    /** A die that always rolls this. 1 passes any percentage; 101 fails every one. */
    private function die(int $value): callable
    {
        return static fn (int $min, int $max): int => $value;
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

    /** ICE is the one still waiting on a turn loop to calibrate it against. */
    public function test_ice_still_says_not_yet(): void
    {
        $this->assertFalse(ScanFact::Ice->isDiscoverable());
        $this->assertTrue(ScanFact::Ids->isDiscoverable());
        $this->assertTrue(ScanFact::Tracer->isDiscoverable());
    }
}
