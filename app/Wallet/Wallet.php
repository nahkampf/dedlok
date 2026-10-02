<?php

declare(strict_types=1);

namespace App\Wallet;

use App\Models\Character;
use App\Models\WalletEntry;

/**
 * The only writer of `characters.creds`, and of `wallet_entries`.
 *
 * Every movement of money goes through here so the ledger cannot miss one.
 * The caller owns the transaction and any funds check (the market and the
 * transfer lock the row first); this adds the number, saves, and writes the
 * line with the balance it left. Balances never go below zero: a caller that
 * would overdraw has already refused, and the floor only stops a skim rounding
 * into a debt.
 */
final class Wallet
{
    /** Positive comes in, negative goes out. Zero writes nothing. */
    public static function move(Character $character, int $amount, Reason $reason, ?string $note = null): void
    {
        if ($amount === 0) {
            return;
        }

        $balance = max(0, (int) $character->creds + $amount);

        $character->forceFill(['creds' => $balance])->save();

        WalletEntry::query()->create([
            'character_id' => $character->getKey(),
            'amount' => $amount,
            'balance' => $balance,
            'reason' => $reason,
            'note' => $note === null ? null : mb_substr($note, 0, 255),
        ]);
    }
}
