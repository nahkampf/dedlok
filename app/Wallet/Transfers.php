<?php

declare(strict_types=1);

namespace App\Wallet;

use App\Models\Character;
use App\Notifications\Category;
use App\Notifications\Notifier;
use App\Support\Text;
use Illuminate\Support\Facades\DB;

/**
 * Cash from one character to another, by handle. No fee, no limit but the balance.
 *
 * Both rows are locked in id order so two people paying each other at once
 * cannot deadlock. The recipient is told through the inbox: money arriving
 * while nobody was looking is what it is for.
 */
final class Transfers
{
    public function send(Character $from, string $handle, int $amount): TransferRefusal|Character
    {
        if ($amount < 1) {
            return TransferRefusal::Amount;
        }

        $to = Character::query()->where('handle', trim($handle))->first();

        if ($to === null) {
            return TransferRefusal::NoSuchCharacter;
        }

        if ($to->is($from)) {
            return TransferRefusal::Yourself;
        }

        return DB::transaction(function () use ($from, $to, $amount): TransferRefusal|Character {
            $locked = Character::query()->whereKey([$from->getKey(), $to->getKey()])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $payer = $locked[$from->getKey()];
            $payee = $locked[$to->getKey()];

            if ((int) $payer->creds < $amount) {
                return TransferRefusal::CannotAfford;
            }

            Wallet::move($payer, -$amount, Reason::TransferOut, Text::get('wallet.notes.sent', ['handle' => $payee->handle]));
            Wallet::move($payee, $amount, Reason::TransferIn, Text::get('wallet.notes.received', ['handle' => $payer->handle]));

            (new Notifier)->send($payee, Category::Wallet, 'transfer_received', [
                'handle' => $payer->handle,
                'amount' => number_format($amount),
            ]);

            $from->forceFill(['creds' => $payer->creds]);

            return $payee;
        });
    }
}
