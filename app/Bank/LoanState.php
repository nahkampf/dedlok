<?php

declare(strict_types=1);

namespace App\Bank;

enum LoanState: string
{
    case Active = 'active';
    case Defaulted = 'defaulted';
    case Repaid = 'repaid';
}
