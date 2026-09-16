<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Support\Text;
use Closure;
use Illuminate\Support\Facades\Config;

/**
 * How hard a system is. One to nine, and the range spans magnitudes.
 *
 * The easiest systems sit comfortably inside a starting character's Synaptic
 * Integrity; the hardest can flatline somebody at 200. That gap is the whole
 * reason scanning exists as a mechanic rather than as flavour — **a grade is
 * the number a scan exists to discover before you find it out the expensive
 * way.**
 *
 * AN UNSCANNED GRADE IS NOT VISIBLE FROM THE MAP, and that is a rule rather
 * than an omission. Sweeping a position tells you a machine is there, what it
 * calls itself, who runs it and what it answers to. What it would cost you to
 * break into is exactly the thing you have to spend something to learn, so
 * nothing may put a grade into `system_contacts` — a sweep produces those, and
 * a sweep is free.
 *
 * A grade somebody SCANNED is theirs to see, and the map draws it as a bar at
 * the foot of the cell. That is the same rule rather than an exception to it:
 * the number is still something you spent minutes and an instrument to learn,
 * and the map is where having learnt it pays off. The only place a cell can get
 * one from is a live `system_scans` row, which is what keeps the two halves
 * from drifting.
 *
 * A class rather than an enum with nine cases: grade is arithmetic — compared,
 * ranged over, rolled — and nine singletons that exist to hold the numbers 1
 * to 9 would be ceremony. What it is NOT is a bare int scattered through the
 * codebase, which is why the bounds and the roll live here.
 *
 * Grade is not `rarity`, which is how often the market stocks an item, and not
 * `tier`, which is what an item is. Three words, three meanings, and getting
 * them crossed is a bug rather than a style preference.
 */
final class Grade
{
    /** A nobody's box. Inside a starting character's reach. */
    public const MIN = 1;

    /** A corporate vault. Ends characters. */
    public const MAX = 9;

    public static function isValid(int $grade): bool
    {
        return $grade >= self::MIN && $grade <= self::MAX;
    }

    /** @return list<int> */
    public static function all(): array
    {
        return range(self::MIN, self::MAX);
    }

    /**
     * A grade for a newly instantiated system.
     *
     * WEIGHTED, AND STEEPLY. A uniform roll would make a third of the grid
     * lethal and the whole map an obstacle course; the world wants to be mostly
     * ordinary machines with the occasional thing that should not be touched.
     * The weights live in config because they are the tuning dial for how
     * dangerous cyberspace feels.
     *
     * The roll is injected for the same reason MarketSeeder's is: a
     * probabilistic generator tested against real randomness is a test that
     * passes most of the time.
     *
     * @param  Closure(int, int): int  $roll  Inclusive both ends, like random_int.
     * @param  ?array<int, int>  $weights  Grade => relative weight.
     */
    public static function roll(Closure $roll, ?array $weights = null): int
    {
        $weights = $weights ?? self::weights();
        $total = array_sum($weights);

        // A misconfigured or empty table is answered with the floor rather
        // than an exception: a world of harmless machines beats no world.
        if ($total <= 0) {
            return self::MIN;
        }

        $ticket = $roll(1, $total);

        foreach ($weights as $grade => $weight) {
            $ticket -= $weight;

            if ($ticket <= 0) {
                return self::isValid((int) $grade) ? (int) $grade : self::MIN;
            }
        }

        return self::MIN;
    }

    /**
     * The configured weights, with anything out of range dropped.
     *
     * @return array<int, int>
     */
    public static function weights(): array
    {
        /** @var array<int|string, int|string> $configured */
        $configured = Config::get('cyberspace.grades', []);
        $weights = [];

        foreach ($configured as $grade => $weight) {
            if (self::isValid((int) $grade) && (int) $weight > 0) {
                $weights[(int) $grade] = (int) $weight;
            }
        }

        return $weights;
    }

    /**
     * The grade as the player reads it: I to IX.
     *
     * A ROMAN NUMERAL EVERYWHERE A GRADE IS SHOWN, and never the arabic digit.
     * A grade is a rank rather than a quantity — nine is not nine of anything —
     * and the numeral says so at a glance, the way a tier does. It also keeps
     * a grade from reading as one of the many actual numbers on these screens:
     * a tracer's rate, a pass count, a detection value.
     *
     * A lookup rather than an algorithm, because the range is fixed at 1-9 and
     * a general converter would be forty lines to answer nine questions. Out of
     * range is clamped rather than thrown: a screen is not where a bad grade
     * should first be noticed.
     */
    public static function roman(int $grade): string
    {
        $numerals = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX'];

        return $numerals[max(self::MIN, min(self::MAX, $grade)) - 1];
    }

    /**
     * What a grade is called once the player has earned the right to see it.
     *
     * Used by the scan and by nothing else — putting a band on the map would
     * leak the number the scan is for.
     */
    public static function label(int $grade): string
    {
        return Text::get('cyberspace.grades.'.max(self::MIN, min(self::MAX, $grade)));
    }
}
