<?php

declare(strict_types=1);

namespace App\Wallet;

use App\Support\Text;

/** What kind of movement a wallet entry is. A new way for creds to move is a case and a label. */
enum Reason: string
{
    case Starting = 'starting';
    case Market = 'market';
    case PlayerMarket = 'player_market';
    case Mission = 'mission';
    case Siphon = 'siphon';
    case Extraction = 'extraction';
    case Hunt = 'hunt';
    case Skim = 'skim';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case Pod = 'pod';

    public function label(): string
    {
        return Text::get("wallet.reasons.{$this->value}");
    }
}
