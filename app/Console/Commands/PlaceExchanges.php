<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Cyberspace\ExchangePlacer;
use Illuminate\Console\Command;

/**
 * Put every interactable entity's front desks on the grid.
 *
 * RUN BY HAND, like `cyberspace:seed` and for the same reason: it writes the
 * world rather than keeping it running. Nothing schedules it, because an
 * Exchange that exists is never moved, shut or replaced — it is the one system
 * a player can walk back to and find where they left it.
 *
 * A TOP-UP. A desk that is already there is left alone, so running this twice
 * places nothing the second time and adding an entity to the roster is a
 * matter of running it again.
 */
class PlaceExchanges extends Command
{
    protected $signature = 'cyberspace:exchanges
                            {--dry-run : Report what would be placed and write nothing}';

    protected $description = 'Place the front desks of every entity somebody can work for';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $placed = ExchangePlacer::fromConfig()->run($dryRun);

        if ($placed === []) {
            $this->info('Every desk is already standing.');

            return self::SUCCESS;
        }

        $this->table(
            ['Entity', 'Grade', 'Position', 'Agent'],
            array_map(static fn (array $row): array => [
                $row['entity'],
                $row['grade'],
                $row['position'],
                $row['agent'],
            ], $placed),
        );

        $this->info(($dryRun ? 'Would place ' : 'Placed ').count($placed).' desk(s).');

        return self::SUCCESS;
    }
}
