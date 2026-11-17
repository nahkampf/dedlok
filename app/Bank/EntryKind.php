<?php

declare(strict_types=1);

namespace App\Bank;

use App\Support\Text;

/** What kind of movement a bank entry is. A new way for the account to move is a case and a label. */
enum EntryKind: string
{
    case Deposit = 'deposit';
    case Withdrawal = 'withdrawal';
    case LoanPayment = 'loan_payment';
    case Seizure = 'seizure';
    case Surplus = 'surplus';

    public function label(): string
    {
        return Text::get("bank.kinds.{$this->value}");
    }
}
