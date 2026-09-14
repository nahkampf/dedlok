<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Cyberspace\GridSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Instantiates the world: the portal, and enough machines to fill it.
 *
 * Run by hand, not on a schedule. Housekeeping will grow its own pass that
 * instantiates a system here and there as the grid churns; this is the one that
 * makes a grid exist at all, and a world is created once.
 *
 * It is a TOP-UP, so running it twice is not two worlds and running it after a
 * burn replaces exactly what was lost. --dry-run prints the arithmetic and
 * writes nothing.
 */
class SeedGrid extends Command
{
    protected $signature = 'cyberspace:seed
                            {--dry-run : Work out what the world needs and write nothing}';

    protected $description = 'Fill the cyberspace grid up to its target occupancy';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $result = GridSeeder::fromConfig()->run(dryRun: $dryRun);

        $summary = sprintf(
            '%d position(s), target %d, %d standing before, %d instantiated, %s%% occupied%s',
            $result->positions,
            $result->target,
            $result->occupiedBefore,
            $result->created,
            $result->occupancy(),
            $result->portalPlaced ? ' (portal placed)' : '',
        );

        if ($result->occupantMoved) {
            $this->warn('A system was standing where the portal goes; it has been moved aside.');
        }

        if ($result->gridFull) {
            $this->warn('The grid ran out of free positions before reaching the target.');
        }

        $this->info(($dryRun ? '[dry run] ' : '').$summary);

        // Like the market's pass: a scheduled command's stdout reaches nothing,
        // and this one will be called from housekeeping soon enough.
        if (! $dryRun) {
            Log::info('cyberspace:seed — '.$summary);
        }

        return self::SUCCESS;
    }
}
