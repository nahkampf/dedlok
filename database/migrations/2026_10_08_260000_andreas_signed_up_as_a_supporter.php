<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill: andreas@anderssons.email became a $2 Supporter on 2026-10-07 00:00:00 UTC, before the Ko-fi webhook existed.
 * Written as the webhook would have: one payment row, and (if the account exists) the flag until a month later. A missing
 * account leaves the payment unmatched, for the admin to connect. No welcome message is sent.
 */
return new class extends Migration
{
    private const EMAIL = 'andreas@anderssons.email';

    private const MESSAGE_ID = 'backfill-andreas-2026-10-07';

    public function up(): void
    {
        $user = DB::table('users')->whereRaw('lower(email) = ?', [self::EMAIL])->first();

        DB::table('kofi_payments')->insertOrIgnore([
            'message_id' => self::MESSAGE_ID,
            'email' => self::EMAIL,
            'tier_name' => 'Supporter',
            'amount' => '2.00',
            'currency' => 'USD',
            'is_first' => true,
            'user_id' => $user?->id,
            'received_at' => '2026-10-07 00:00:00',
        ]);

        if ($user !== null) {
            DB::table('users')->where('id', $user->id)->update([
                'is_supporter' => true,
                'supporter_since' => '2026-10-07 00:00:00',
                'supporter_until' => '2026-11-07 00:00:00',
            ]);
        }
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
