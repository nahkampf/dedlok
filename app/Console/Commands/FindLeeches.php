<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Cyberspace\Leeches;
use Illuminate\Console\Command;

/** Nightly: every Leecher is rolled once against `cyberspace.fortress.leech_discovery` per cent. A missed night is not made up. */
class FindLeeches extends Command
{
    protected $signature = 'cyberspace:find-leeches
                            {--dry-run : Report what would be removed and write nothing}
                            {--quiet-when-idle : Print nothing unless something was found}';

    protected $description = 'Roll every Leecher for discovery, and tell the owner of any that were found';

    public function handle(Leeches $leeches): int
    {
        $found = $leeches->discover(static fn (int $min, int $max): int => random_int($min, $max), (bool) $this->option('dry-run'));

        if ($found > 0 || ! $this->option('quiet-when-idle')) {
            $this->info("Leechers found: {$found}".($this->option('dry-run') ? ' (dry run)' : ''));
        }

        return self::SUCCESS;
    }
}
