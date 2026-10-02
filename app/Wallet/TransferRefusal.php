<?php

declare(strict_types=1);

namespace App\Wallet;

use App\Support\Text;

enum TransferRefusal: string
{
    case Amount = 'amount';
    case NoSuchCharacter = 'no_such_character';
    case Yourself = 'yourself';
    case CannotAfford = 'cannot_afford';

    public function message(): string
    {
        return Text::get("wallet.refused.{$this->value}");
    }
}
