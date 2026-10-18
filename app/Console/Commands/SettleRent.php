<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Character;
use App\Safehouses\Renter;
use App\Safehouses\SafehouseStorage;
use Illuminate\Console\Command;

/**
 * Rent that fell due while nobody was looking. Lazy settling (the City screens) is what is exact; this keeps the
 * rows true between visits and takes a safehouse from someone who stayed away unpaid.
 */
class SettleRent extends Command
{
    protected $signature = 'safehouses:rent {--quiet-when-idle}';

    protected $description = 'Charge safehouse rent that has fallen due, and take the safehouse of anyone who has not paid';

    public function handle(Renter $renter, SafehouseStorage $storage): int
    {
        $n = 0;

        Character::query()->whereNotNull('safehouse_id')->where('rent_due_at', '<=', now())->each(function (Character $c) use ($renter, &$n): void {
            $renter->settle($c);
            $n++;
        });

        $gone = $storage->sweep();

        if ($n > 0 || $gone > 0 || ! $this->option('quiet-when-idle')) {
            $this->info("Rent settled for {$n} character(s); {$gone} uncollected stored row(s) deleted.");
        }

        return self::SUCCESS;
    }
}
