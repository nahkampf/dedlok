<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Beta\AlphaGift;
use Illuminate\Console\Command;

/** Pay the alpha-testing gift to every account that registered with a key. Safe to run again: nobody is paid twice. */
class GiftTesters extends Command
{
    protected $signature = 'testers:gift';

    protected $description = 'Give every alpha tester their one-off gift of creds';

    public function handle(AlphaGift $gift): int
    {
        $this->info("Paid {$gift->grantAll()} tester(s) ".AlphaGift::AMOUNT.' ¤ each.');

        return self::SUCCESS;
    }
}
