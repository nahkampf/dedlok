<?php

declare(strict_types=1);

namespace App\Beta;

use App\Models\BetaKey;
use App\Models\User;
use App\Support\Text;
use Illuminate\Validation\ValidationException;

/**
 * Beta keys: the only writer of `beta_keys`, and the one place the alpha testing flag is asked about registration.
 *
 * THE FLAG IS `config('features.alpha_testing')`. Off, nothing here is consulted and registration is open.
 *
 * A code is canonical when it is upper case in groups of four joined by dashes, from an alphabet with nothing that
 * can be misread (no 0/O, 1/I/L). `normalise()` accepts what a person types — lower case, no dashes, stray spaces —
 * and turns it into that, so nobody is refused for how they copied it.
 */
final class BetaKeys
{
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const GROUPS = 3;

    public static function enabled(): bool
    {
        return (bool) config('features.alpha_testing');
    }

    public function generate(): BetaKey
    {
        do {
            $code = $this->make();
        } while (BetaKey::query()->where('code', $code)->exists());

        return BetaKey::query()->create(['code' => $code]);
    }

    /** What an admin did when they copied it: it is out of their pocket. Idempotent. */
    public function markGivenOut(BetaKey $key): void
    {
        if ($key->given_out_at === null) {
            $key->forceFill(['given_out_at' => now()])->save();
        }
    }

    /** Whatever was typed, as the canonical form; null when it cannot be one. */
    public function normalise(?string $typed): ?string
    {
        $clean = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $typed)) ?? '';

        if (strlen($clean) !== self::GROUPS * 4) {
            return null;
        }

        return implode('-', str_split($clean, 4));
    }

    /**
     * Spend a key on a new account, or refuse with a validation error on `code`.
     *
     * Called INSIDE the transaction that creates the user, with the row locked, so two people registering with the
     * same key cannot both get through. The refusal is one sentence for a key that does not exist and one that is
     * spent: which it was is not a thing to tell somebody guessing.
     *
     * @throws ValidationException
     */
    public function claim(?string $typed, User $user): void
    {
        $code = $this->normalise($typed);

        $key = $code === null ? null : BetaKey::query()->where('code', $code)->lockForUpdate()->first();

        if ($key === null || $key->isUsed()) {
            throw ValidationException::withMessages(['code' => Text::get('auth.beta.invalid')]);
        }

        $key->forceFill(['user_id' => $user->getKey(), 'used_at' => now()])->save();
    }

    /** Checked before anything is created, so a bad key costs no account. */
    public function assertUsable(?string $typed): void
    {
        $code = $this->normalise($typed);

        if ($code === null || ! BetaKey::query()->where('code', $code)->whereNull('used_at')->exists()) {
            throw ValidationException::withMessages(['code' => Text::get('auth.beta.invalid')]);
        }
    }

    private function make(): string
    {
        $groups = [];

        for ($g = 0; $g < self::GROUPS; $g++) {
            $group = '';

            for ($i = 0; $i < 4; $i++) {
                $group .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }

            $groups[] = $group;
        }

        return implode('-', $groups);
    }
}
