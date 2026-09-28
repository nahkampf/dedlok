<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Standing\StandingLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

/**
 * Opinions fade, from both directions.
 *
 * A QUARTER OF A PERCENT OF WHAT IS CARRIED, toward zero and never past it
 * (`standing.drift.nightly`). A flat rate would be backwards at the size of
 * what earns standing: a mission is worth hundredths of a point, so half a
 * point a night would erase a day's work at the bottom of the ladder and cost
 * nothing at the top.
 *
 * It is also the only balance this system has. Standing settles where a day's
 * earnings equal the drift — 400x the daily rate — so each grade of work has
 * a ceiling nobody had to write a rule for.
 *
 * ## Every move writes a ledger entry
 *
 * The books equal the sum of the ledger, always. A drift that moved the total
 * quietly would break the one invariant the design rests on, which is why this
 * goes through `StandingLedger` like everything else rather than running an
 * UPDATE across the table.
 *
 * The usual stance: no stored clock, a missed night is never made up,
 * `withoutOverlapping()` in the schedule because two passes are two nights.
 */
class DriftStanding extends Command
{
    protected $signature = 'standing:drift
                            {--quiet-when-idle : Print nothing unless something moved}';

    protected $description = 'Drift every standing a little closer to neutral';

    public function handle(StandingLedger $ledger): int
    {
        $moved = $ledger->drift();

        if ($moved === 0 && (bool) $this->option('quiet-when-idle')) {
            return self::SUCCESS;
        }

        $rate = (float) Config::get('standing.drift.nightly', 0.0025);

        $this->info($moved === 0
            ? 'No standing to drift.'
            : "Drifted {$moved} set(s) of books by ".round($rate * 100, 2).'% toward neutral.');

        return self::SUCCESS;
    }
}
