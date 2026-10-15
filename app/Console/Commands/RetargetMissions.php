<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Missions\MissionLedger;
use App\Models\Mission;
use Illuminate\Console\Command;

/** Give every accepted job a new target where one has gone dark, without waiting for somebody to open a screen. */
class RetargetMissions extends Command
{
    protected $signature = 'missions:retarget';

    protected $description = 'Replace the lost targets of accepted missions with new systems of the same grade and type';

    public function handle(MissionLedger $ledger): int
    {
        $stuck = 0;

        foreach (Mission::query()->accepted()->cursor() as $mission) {
            if (! $ledger->retarget($mission)) {
                $stuck++;
            }
        }

        $this->info("Done. {$stuck} job(s) have a lost target and nothing to replace it (they void at hand-in or expiry).");

        return self::SUCCESS;
    }
}
