<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Cyberspace\Lockdown;
use App\Models\System;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Open the machines whose lockdown has run out, and move them.
 *
 * ## The one thing in cyberspace that is NOT settled lazily
 *
 * A matured scan pass, a training deposit and the waiting screen are all
 * resolved when somebody next looks, and this deliberately is not.
 * docs/CYBERSPACE.md gives the reason in one line: two machines relocating into the
 * same free position is a race, and a sweep is what serialises it. Lazy
 * settlement would have every request that drew a map racing every other one
 * for the same empty square.
 *
 * It is also the only way the rule can hold for somebody who is not looking. A
 * lockdown is 2-6 hours; the player who caused it has gone to bed, and the
 * machine has to open on its own clock rather than on their next page load.
 *
 * ## The usual stance
 *
 * `market:seed`'s: no stored clock, a missed pass is never made up, and a pass
 * that runs late simply moves what was due. `withoutOverlapping()`, because two
 * sweeps in flight would both draw positions for the same machines. `--dry-run`
 * decides nothing and writes nothing.
 */
class ExpireLockdowns extends Command
{
    protected $signature = 'cyberspace:lockdowns
                            {--dry-run : Report what is due and move nothing}
                            {--quiet-when-idle : Print nothing unless something moved}';

    protected $description = 'Relocate every machine whose lockdown has run out';

    public function handle(Lockdown $lockdown): int
    {
        $due = System::query()
            ->whereNotNull('locked_down_until')
            ->where('locked_down_until', '<=', now())
            ->count();

        if ((bool) $this->option('dry-run')) {
            $this->info($due === 0
                ? 'No lockdown has run out.'
                : "{$due} machine(s) are due to open and move. Nothing was written.");

            return self::SUCCESS;
        }

        $moved = $lockdown->settle();

        if ($moved === 0 && (bool) $this->option('quiet-when-idle')) {
            return self::SUCCESS;
        }

        // A machine that opened but could not move is not an error — the grid
        // was full at that instant — but it is worth a line, because a world
        // with nowhere left to move things is a seeding problem somebody
        // should hear about before it becomes the normal state.
        if ($moved < $due) {
            Log::warning('cyberspace:lockdowns opened machines it could not relocate', [
                'due' => $due,
                'moved' => $moved,
            ]);
        }

        $this->info("{$due} machine(s) due, {$moved} relocated.");

        return self::SUCCESS;
    }
}
