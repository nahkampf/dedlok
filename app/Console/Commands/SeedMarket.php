<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Market\MarketSeeder;
use App\Market\SeedOutcome;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The market's delivery run. Scheduled every eight hours; see routes/console.php.
 *
 * Unlike the training heartbeat, a missed pass is NOT made up: there is no
 * stored clock to advance from, and a pass that ran twice would be two
 * deliveries rather than one. A pass that never ran is simply a shelf that
 * stays as it is for another eight hours, which is a thing players cannot tell
 * from a run of bad rarity rolls.
 *
 * The summary goes through Log:: as well as the console, because a scheduled
 * command's stdout reaches nothing (see routes/console.php).
 */
class SeedMarket extends Command
{
    protected $signature = 'market:seed
                            {--dry-run : Work out the delivery and write nothing}';

    protected $description = 'Restock the market, weighing recent demand against each item rarity';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $result = MarketSeeder::fromConfig()->run(dryRun: $dryRun);

        // The per-item picture is what makes a probabilistic pass readable, so
        // a dry run always shows it -- there is nothing else it could report.
        if ($dryRun || $this->output->isVerbose()) {
            $this->table(
                ['Item', 'Rarity', 'Roll', 'Demand', 'Target', 'Stock', 'Added'],
                array_map($this->row(...), $result->outcomes),
            );
        }

        $summary = sprintf(
            '%d item(s) considered, %d restocked, %d passed over on rarity, %s unit(s) added',
            $result->itemsConsidered(),
            $result->itemsStocked(),
            $result->itemsPassedOver(),
            number_format($result->unitsAdded()),
        );

        $this->line($dryRun ? "{$summary} (dry run, nothing written)" : $summary);

        if (! $dryRun) {
            Log::info("Market seed: {$summary}");
        }

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function row(SeedOutcome $outcome): array
    {
        return [
            $outcome->item->slug,
            $outcome->item->rarity->value,
            $outcome->stocked
                ? (string) $outcome->roll
                : "<fg=gray>{$outcome->roll}</>",
            (string) $outcome->demand,
            (string) $outcome->target,
            "{$outcome->before} → {$outcome->after()}",
            $outcome->added > 0 ? "<info>+{$outcome->added}</info>" : '—',
        ];
    }
}
