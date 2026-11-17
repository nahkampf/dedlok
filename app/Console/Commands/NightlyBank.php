<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Bank\Loans;
use Illuminate\Console\Command;

/** Kronos Capital's night: interest on every open loan, standing for balances held, defaults pinned back to -5. */
class NightlyBank extends Command
{
    protected $signature = 'bank:nightly {--quiet-when-idle}';

    protected $description = 'Charge a night of loan interest, pay standing for held balances and re-pin defaulted standing';

    public function handle(Loans $loans): int
    {
        $n = $loans->nightly();

        if ($n > 0 || ! $this->option('quiet-when-idle')) {
            $this->info("{$n} loan(s) charged.");
        }

        return self::SUCCESS;
    }
}
