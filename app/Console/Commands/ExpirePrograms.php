<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Programs\Lifetime;
use Illuminate\Console\Command;

/** Delete every running Script whose time is up. Reading the running rows does it for whoever is looking; this is for the rest. */
class ExpirePrograms extends Command
{
    protected $signature = 'programs:expire';

    protected $description = 'Spend the running Scripts whose lifetime has ended';

    public function handle(): int
    {
        $this->info(Lifetime::reapAll().' spent.');

        return self::SUCCESS;
    }
}
