<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Standing\StandingLedger;
use Illuminate\Console\Command;

/**
 * Do the books still equal the ledger?
 *
 * THE INVARIANT THE WHOLE DESIGN RESTS ON, asked out loud. `entity_books`
 * carries a running total beside an immutable log of every change, which is
 * two copies of one number — and two copies are only safe while something can
 * say they agree.
 *
 * It is three lines because of how the entries are written: every delta is
 * what LANDED, already clamped, so summing the column reproduces the total
 * exactly. Anything this reports is a writer that went around
 * `StandingLedger`, which is a bug rather than a state.
 *
 * Exits non-zero while anything disagrees, so it can sit in CI beside
 * `db:comments` and `skills:validate`.
 */
class VerifyStanding extends Command
{
    protected $signature = 'standing:verify';

    protected $description = 'Check every set of entity books against its ledger';

    public function handle(StandingLedger $ledger): int
    {
        $wrong = $ledger->verify();

        if ($wrong === []) {
            $this->info('Standing and loyalty are consistent.');

            return self::SUCCESS;
        }

        foreach ($wrong as $row) {
            $this->error(sprintf(
                'character %d / entity %d / %s: books %.3f, ledger %.3f',
                $row['character_id'],
                $row['entity_id'],
                $row['kind'],
                $row['books'],
                $row['ledger'],
            ));
        }

        $this->error(count($wrong).' set(s) of books disagree with the ledger.');

        return self::FAILURE;
    }
}
