<?php

declare(strict_types=1);

namespace App\Bank;

use App\Models\BankAccount;
use App\Models\BankEntry;
use App\Models\Character;
use App\Models\Loan;
use App\Support\Text;
use App\Wallet\Reason;
use App\Wallet\Wallet;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Kronos Capital's accounts. docs/BANK.md.
 *
 * THE ONLY WRITER of `bank_accounts` and `bank_entries`, and every movement is a ledger line carrying the balance it
 * left, like the wallet's. Cash moves through `Wallet::move()`, so `characters.creds` keeps its one writer. The caller
 * has already decided the character may be at a bank (the City branch or Kronos' Exchange); this only moves money.
 * The money in an account is out of reach of everything that takes creds from a wallet.
 */
final class Bank
{
    /** What the character has banked. Zero without an account: nothing is made until the first deposit. */
    public function balance(Character $character): int
    {
        return (int) BankAccount::query()->where('character_id', $character->getKey())->value('balance');
    }

    /** The last lines of the statement, newest first. */
    public function statement(Character $character, int $limit = 10): Collection
    {
        return BankEntry::query()
            ->whereIn('bank_account_id', BankAccount::query()->where('character_id', $character->getKey())->select('id'))
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /** @return int|BankRefusal the new account balance */
    public function deposit(Character $character, int $amount): int|BankRefusal
    {
        return $this->move($character, $amount, EntryKind::Deposit);
    }

    /** @return int|BankRefusal the new account balance */
    public function withdraw(Character $character, int $amount): int|BankRefusal
    {
        return $this->move($character, $amount, EntryKind::Withdrawal);
    }

    /** Creds into the account that did not come from the wallet: what a seizure took over the debt. The caller owns the transaction. */
    public function credit(Character $character, int $amount, EntryKind $kind): void
    {
        $account = BankAccount::query()->firstOrCreate(['character_id' => $character->getKey()]);
        $account = BankAccount::query()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();

        $account->forceFill(['balance' => $account->balance + $amount])->save();
        BankEntry::query()->create(['bank_account_id' => $account->getKey(), 'amount' => $amount, 'balance' => $account->balance, 'kind' => $kind, 'note' => Text::get('bank.notes.'.$kind->value)]);
    }

    /** Whether the account is frozen: a loan is in default and no settlement has been agreed. */
    public function frozen(Character $character): bool
    {
        return Loan::query()->where('character_id', $character->getKey())
            ->where('state', LoanState::Defaulted->value)->whereNull('settlement')->exists();
    }

    /**
     * Take creds out of the account for a loan payment. The caller owns the transaction. False when it cannot cover it.
     * The freeze does not apply: it stops withdrawals, not paying the debt.
     */
    public function spend(Character $character, int $amount, EntryKind $kind): bool
    {
        $account = BankAccount::query()->where('character_id', $character->getKey())->lockForUpdate()->first();

        if ($account === null || $account->balance < $amount) {
            return false;
        }

        $account->forceFill(['balance' => $account->balance - $amount])->save();
        BankEntry::query()->create(['bank_account_id' => $account->getKey(), 'amount' => -$amount, 'balance' => $account->balance, 'kind' => $kind, 'note' => Text::get('bank.notes.'.$kind->value)]);

        return true;
    }

    private function move(Character $character, int $amount, EntryKind $kind): int|BankRefusal
    {
        if ($amount < 1) {
            return BankRefusal::Amount;
        }

        return DB::transaction(function () use ($character, $amount, $kind): int|BankRefusal {
            $locked = Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail();
            BankAccount::query()->firstOrCreate(['character_id' => $locked->getKey()]);
            $account = BankAccount::query()->where('character_id', $locked->getKey())->lockForUpdate()->firstOrFail();

            $in = $kind === EntryKind::Deposit;

            if ($in && (int) $locked->creds < $amount) {
                return BankRefusal::NoCash;
            }

            if (! $in && $this->frozen($locked)) {
                return BankRefusal::Frozen;
            }

            if (! $in && $account->balance < $amount) {
                return BankRefusal::NoFunds;
            }

            $signed = $in ? $amount : -$amount;
            $account->forceFill(['balance' => $account->balance + $signed])->save();

            BankEntry::query()->create([
                'bank_account_id' => $account->getKey(),
                'amount' => $signed,
                'balance' => $account->balance,
                'kind' => $kind,
                'note' => Text::get('bank.notes.'.$kind->value),
            ]);

            Wallet::move($locked, -$signed, $in ? Reason::BankDeposit : Reason::BankWithdrawal, Text::get('bank.notes.'.$kind->value));

            $character->forceFill(['creds' => $locked->creds]);

            return $account->balance;
        });
    }
}
