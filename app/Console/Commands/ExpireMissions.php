<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Missions\MissionLedger;
use Illuminate\Console\Command;

/**
 * The clock running out on work somebody took.
 *
 * ## Why this is a sweep and not lazy
 *
 * The penalty has to land whether or not the player ever comes back. A
 * mission settled on the next visit would mean somebody who takes a job and
 * never opens the screen again keeps the standing they were about to lose —
 * and worse, an agent's books would disagree with the clock everyone can see.
 * `cyberspace:lockdowns` answers the same problem the same way.
 *
 * FIVE MINUTES, the cadence the lockdown sweep already uses. A window measured
 * in hours does not need a finer one, and a job that fails four minutes late
 * failed anyway.
 *
 * ## A lost target still voids, even here
 *
 * `MissionLedger::sweepExpired()` checks the targets before it charges
 * anybody: the clock running out on a job that had become impossible is still
 * not the runner's fault, and a void costs nothing at any grade.
 */
class ExpireMissions extends Command
{
    protected $signature = 'missions:expire
                            {--quiet-when-idle : Print nothing unless something ended}';

    protected $description = 'Fail the missions whose window has closed, and void the ones whose targets have gone';

    public function handle(MissionLedger $ledger): int
    {
        $ended = $ledger->sweepExpired();

        $total = $ended['failed'] + $ended['voided'];

        if ($total === 0 && (bool) $this->option('quiet-when-idle')) {
            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d mission(s) failed, %d voided.',
            $ended['failed'],
            $ended['voided'],
        ));

        return self::SUCCESS;
    }
}
