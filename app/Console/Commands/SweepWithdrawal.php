<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Drugs\DrugLedger;
use App\Drugs\Family;
use App\Models\Character;
use App\Models\DrugUse;
use Illuminate\Console\Command;

/**
 * Making the withdrawal rows true between visits.
 *
 * ## Why this is a sweep and not lazy
 *
 * Taking a dose clears withdrawal on the spot — that path has somebody
 * pressing a button. The other direction has nobody: a dose wears off at some
 * arbitrary minute, and the row that says the character is going without has
 * to appear then rather than the next time anything happens to them. Cyberspace
 * has the same problem with lockdown windows and answers it the same way.
 *
 * FIVE MINUTES, like `cyberspace:settle` and `cyberspace:lockdowns`, and for
 * the reason those run: what it buys is a row that is TRUE between visits,
 * which is what every reader that is not the owner needs.
 *
 * It only looks at characters who have books at all, so a world where nobody
 * has ever taken anything costs one query.
 */
class SweepWithdrawal extends Command
{
    protected $signature = 'drugs:withdraw
                            {--quiet-when-idle : Print nothing unless somebody is in withdrawal}';

    protected $description = 'Write or clear the withdrawal each character is owed, family by family';

    public function handle(DrugLedger $ledger): int
    {
        $hooked = 0;

        foreach (DrugUse::query()->orderBy('character_id')->cursor() as $books) {
            $character = Character::query()->find($books->character_id);

            if ($character === null) {
                continue;
            }

            if ($ledger->reconcile($character, $books->family instanceof Family ? $books->family : Family::from((string) $books->family))) {
                $hooked++;
            }
        }

        if ($hooked === 0 && (bool) $this->option('quiet-when-idle')) {
            return self::SUCCESS;
        }

        $this->info(sprintf('%d character-famil(ies) in withdrawal.', $hooked));

        return self::SUCCESS;
    }
}
