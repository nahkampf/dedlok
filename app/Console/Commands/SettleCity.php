<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\City\CityClock;
use Illuminate\Console\Command;

/** Settle every travel or exploring pass that has matured, and tell the player. */
class SettleCity extends Command
{
    protected $signature = 'city:settle';

    protected $description = 'Settle matured City activities';

    public function handle(CityClock $clock): int
    {
        $this->info(sprintf('%d character(s) settled.', $clock->sweep()));

        return self::SUCCESS;
    }
}
