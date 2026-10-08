<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Crafting\Crafting;
use Illuminate\Console\Command;

/** Credit a minute of work to every build whose deck is ready, and deliver the ones that are done. */
class SettleCrafting extends Command
{
    protected $signature = 'crafting:settle {--quiet-when-idle : Print nothing unless something was delivered}';

    protected $description = 'Advance every build in progress and deliver the finished ones';

    public function handle(Crafting $crafting): int
    {
        $delivered = $crafting->settleAll();

        if ($delivered > 0 || ! $this->option('quiet-when-idle')) {
            $this->info("{$delivered} delivered.");
        }

        return self::SUCCESS;
    }
}
