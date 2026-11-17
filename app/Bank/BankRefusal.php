<?php

declare(strict_types=1);

namespace App\Bank;

use App\Support\Text;

/** Why the bank did nothing. */
enum BankRefusal: string
{
    /** Not a whole number of creds above nothing. */
    case Amount = 'amount';

    /** More than the wallet holds. */
    case NoCash = 'no_cash';

    /** The account is frozen: a loan is in default. */
    case Frozen = 'frozen';

    /** More than the account holds. */
    case NoFunds = 'no_funds';

    public function message(): string
    {
        return Text::get("bank.refused.{$this->value}");
    }
}
