<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Cyberspace\NodeStocker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Fill the world's machines back up.
 *
 * Nightly, which is the cadence CYBERSPACE.md gives the world's slow metabolism
 * — and it follows `market:seed`'s precedent exactly: no stored clock, a missed
 * pass is never made up, a pass run twice is not two hauls because it tops up
 * rather than adds, and `withoutOverlapping()` keeps two of them apart.
 *
 * EMPTYING A NODE IS TEMPORARY, which is what this command is for. A quiet
 * thief has somewhere to come back to; what stops that being a permanent
 * income is the raid counter and the detection floor rather than scarcity.
 *
 * --dry-run prints the decision table and writes nothing. Worth running before
 * trusting a tuning change: the numbers below are unmeasured, because nothing
 * can raid a node yet.
 */
class RestockNodes extends Command
{
    protected $signature = 'cyberspace:restock
                            {--dry-run : Work out what every empty node would get and write nothing}
                            {--sample=10 : How many nodes to print in the decision table}';

    protected $description = 'Restock the contents of every emptied machine on the grid';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = NodeStocker::fromConfig()->run(dryRun: $dryRun);

        $summary = sprintf(
            '%d node(s) restocked: %s creds, %d file(s), %d item(s)',
            $result->nodes,
            number_format($result->creds),
            $result->files,
            $result->items,
        );

        if ($dryRun) {
            $this->table(
                ['address', 'grade', 'creds', 'file QB', 'item'],
                collect($result->rows)
                    ->take(max(0, (int) $this->option('sample')))
                    ->map(fn (array $row): array => [
                        $row['address'],
                        $row['grade'],
                        number_format($row['creds']),
                        $row['file'] ?? '—',
                        $row['item'] ? 'yes' : '—',
                    ])
                    ->all(),
            );

            $this->info("Dry run: {$summary}. Nothing was written.");

            return self::SUCCESS;
        }

        // Logged for the same reason the market's pass is: this runs while
        // nobody is watching, and a world that quietly stopped restocking looks
        // exactly like a world nobody is robbing.
        Log::info("cyberspace:restock — {$summary}");
        $this->info($summary);

        return self::SUCCESS;
    }
}
