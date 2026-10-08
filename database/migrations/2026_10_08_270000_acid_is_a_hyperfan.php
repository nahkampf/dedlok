<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill: the account of the character ACiD is a Hyperfan, for good. Recorded as a payment with the tier and no amount (nothing
 * was paid), so the supporter history names the tier; the flag has no end date. No welcome message. Does nothing if there is no ACiD.
 */
return new class extends Migration
{
    private const MESSAGE_ID = 'backfill-acid-hyperfan';

    public function up(): void
    {
        $user = DB::table('users')->join('characters', 'characters.user_id', '=', 'users.id')
            ->where('characters.handle', 'ACiD')->select('users.id', 'users.email', 'users.supporter_since')->first();

        if ($user === null) {
            return;
        }

        DB::table('kofi_payments')->insertOrIgnore([
            'message_id' => self::MESSAGE_ID,
            'email' => strtolower($user->email),
            'tier_name' => 'Hyperfan',
            'amount' => null,
            'currency' => null,
            'is_first' => true,
            'user_id' => $user->id,
            'received_at' => '2026-10-08 00:00:00',
        ]);

        DB::table('users')->where('id', $user->id)->update([
            'is_supporter' => true,
            'supporter_since' => $user->supporter_since ?? '2026-10-08 00:00:00',
            'supporter_until' => null,
        ]);
    }

    public function down(): void
    {
        $payment = DB::table('kofi_payments')->where('message_id', self::MESSAGE_ID)->first();

        if ($payment?->user_id !== null) {
            DB::table('users')->where('id', $payment->user_id)->update(['is_supporter' => false, 'supporter_since' => null, 'supporter_until' => null]);
        }

        DB::table('kofi_payments')->where('message_id', self::MESSAGE_ID)->delete();
    }
};
