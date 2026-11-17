<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Bank\Loans;
use Illuminate\Console\Command;

/** Kronos Capital's 15-minute sweep: collect instalments that fell due, start grace clocks, default what ran out. */
class SweepLoans extends Command
{
    protected $signature = 'bank:sweep {--quiet-when-idle}';

    protected $description = 'Collect loan instalments that are due, and default loans whose grace has run out';

    public function handle(Loans $loans): int
    {
        $n = $loans->sweep();

        if ($n > 0 || ! $this->option('quiet-when-idle')) {
            $this->info("{$n} loan(s) swept.");
        }

        return self::SUCCESS;
    }
}
