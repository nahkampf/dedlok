<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Drugs\DrugLedger;
use App\Models\Dose;
use App\Models\DrugUse;
use Illuminate\Console\Command;

/**
 * A day of doing nothing at all.
 *
 * Both figures come down: tolerance faster than addiction, so a break gives
 * the drug its strength back long before it gives the need up — the player who
 * comes back after a fortnight gets the good half first and the bill
 * afterwards, which is the honest shape of the thing being modelled.
 *
 * ## It also sweeps up what has worn off
 *
 * Expired doses are filtered on READ, so this is housekeeping rather than
 * correctness: `App\Stats\Sources\ActiveDoses` has never counted them. What it
 * buys is a table that does not grow for ever.
 *
 * ## The usual stance
 *
 * `market:seed`'s, like every other housekeeping job here: no stored clock, a
 * missed night is never made up, and `withoutOverlapping()` because two passes
 * would be two days of decay in one night.
 */
class MetaboliseDrugs extends Command
{
    protected $signature = 'drugs:metabolise
                            {--dry-run : Report what would come off and write nothing}
                            {--quiet-when-idle : Print nothing unless something moved}';

    protected $description = 'Take a day off every addiction and tolerance figure, and sweep up doses that have worn off';

    public function handle(DrugLedger $ledger): int
    {
        if ((bool) $this->option('dry-run')) {
            $this->info(sprintf(
                '%d set(s) of books would move and %d worn-off dose(s) would be swept. Nothing was written.',
                DrugUse::query()->count(),
                Dose::query()->where('expires_at', '<=', now())->count(),
            ));

            return self::SUCCESS;
        }

        $moved = $ledger->decay();
        $swept = Dose::query()->where('expires_at', '<=', now())->delete();

        if ($moved === 0 && $swept === 0 && (bool) $this->option('quiet-when-idle')) {
            return self::SUCCESS;
        }

        $this->info(sprintf('%d set(s) of books moved, %d worn-off dose(s) swept.', $moved, $swept));

        return self::SUCCESS;
    }
}
