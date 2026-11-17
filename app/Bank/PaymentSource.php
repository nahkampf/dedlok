<?php

declare(strict_types=1);

namespace App\Bank;

/** What paid a loan payment. */
enum PaymentSource: string
{
    case Account = 'account';
    case Wallet = 'wallet';
    case Skim = 'skim';
    case Seizure = 'seizure';
}
