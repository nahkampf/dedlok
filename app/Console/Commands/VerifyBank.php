<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BankAccount;
use App\Models\Loan;
use Illuminate\Console\Command;

/** Asks whether every account's balance is what its statement sums to, and whether each line carries the balance it left; and that each loan's principal is what was borrowed less what was paid. */
class VerifyBank extends Command
{
    protected $signature = 'bank:verify';

    protected $description = 'Check that every bank account equals the sum of its ledger';

    public function handle(): int
    {
        $bad = 0;

        foreach (BankAccount::query()->cursor() as $account) {
            $sum = (int) $account->entries()->sum('amount');
            $last = $account->entries()->orderByDesc('id')->value('balance');

            if ((int) $account->balance !== $sum || ($last !== null && (int) $last !== (int) $account->balance)) {
                $this->error("Account {$account->getKey()} (character {$account->character_id}): balance {$account->balance}, ledger sums to {$sum}.");
                $bad++;
            }
        }

        foreach (Loan::query()->cursor() as $loan) {
            $paid = (int) $loan->payments()->sum('principal');

            if ($loan->borrowed - $paid !== $loan->principal) {
                $this->error("Loan {$loan->getKey()}: principal {$loan->principal}, but {$loan->borrowed} borrowed less {$paid} paid.");
                $bad++;
            }
        }

        $bad === 0 ? $this->info('Every account matches its ledger.') : $this->error("{$bad} account(s) are off.");

        return $bad === 0 ? self::SUCCESS : self::FAILURE;
    }
}
