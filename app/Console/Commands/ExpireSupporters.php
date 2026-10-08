<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Supporters\Supporters;
use Illuminate\Console\Command;

/** Takes the Supporter flag from accounts whose time has run out. */
class ExpireSupporters extends Command
{
    protected $signature = 'supporters:expire';

    protected $description = 'Take the Supporter flag from accounts whose time has run out';

    public function handle(Supporters $supporters): int
    {
        $this->info($supporters->expire().' supporter(s) lapsed.');

        return self::SUCCESS;
    }
}
