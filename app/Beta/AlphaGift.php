<?php

declare(strict_types=1);

namespace App\Beta;

use App\Models\BetaKey;
use App\Models\Character;
use App\Models\WalletEntry;
use App\Wallet\Reason;
use App\Wallet\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * The thank-you for everybody who registered with an alpha key: a one-off sum, once.
 *
 * ONCE IS THE LEDGER'S ANSWER: a character already holding an `alpha_gift` line is not paid again, so the command can be
 * run as often as anybody likes and character creation can call it without asking whether the command ran first.
 * Money still moves only through `Wallet`.
 */
final class AlphaGift
{
    public const AMOUNT = 5000;

    /** Pay this character if their account spent a beta key and they have not been paid. True when it paid. */
    public function grant(Character $character): bool
    {
        return DB::transaction(function () use ($character): bool {
            $locked = Character::query()->lockForUpdate()->find($character->getKey());

            if ($locked === null
                || ! BetaKey::query()->where('user_id', $locked->user_id)->exists()
                || WalletEntry::query()->where('character_id', $locked->getKey())->where('reason', Reason::AlphaGift->value)->exists()) {
                return false;
            }

            Wallet::move($locked, self::AMOUNT, Reason::AlphaGift);
            $character->forceFill(['creds' => $locked->creds]);

            return true;
        });
    }

    /** Everybody who is owed it, for the command. @return int how many were paid */
    public function grantAll(): int
    {
        $paid = 0;

        Character::query()
            ->whereIn('user_id', BetaKey::query()->whereNotNull('user_id')->select('user_id'))
            ->each(function (Character $character) use (&$paid): void {
                $paid += $this->grant($character) ? 1 : 0;
            });

        return $paid;
    }
}
