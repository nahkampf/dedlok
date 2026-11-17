<?php

declare(strict_types=1);

namespace App\Bank;

use App\Support\Text;

/** Why a loan action did nothing. */
enum LoanRefusal: string
{
    /** One open loan at a time. */
    case HaveLoan = 'have_loan';

    /** Under the smallest loan, or not a whole number. */
    case TooSmall = 'too_small';

    /** Standing with Kronos is below 0: no loan at all. */
    case BadStanding = 'bad_standing';

    /** Over what their standing with Kronos allows. */
    case OverLimit = 'over_limit';

    /** There is no open loan. */
    case NoLoan = 'no_loan';

    /** No instalment is due yet: ahead of schedule is the early payoff. */
    case NotDue = 'not_due';

    /** Not enough in the account or the wallet to pay it. */
    case CannotPay = 'cannot_pay';

    public function message(): string
    {
        return Text::get("bank.loan_refused.{$this->value}");
    }
}
