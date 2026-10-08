<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Supporters\Supporters;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ko-fi's webhook: a membership payment makes the payer a supporter for a month from the moment it arrives.
 *
 * Ko-fi posts `data=<JSON>` as a form. It is trusted only by the `verification_token` inside it (KOFI_VERIFICATION_TOKEN; unset
 * fails closed). Anything that is not a subscription payment is acknowledged and ignored. The payer is matched by email to an
 * account; an unmatched payment is kept and answered 200 (a retry would not change it). Each message_id is applied once.
 */
class KofiWebhookController extends Controller
{
    public function __invoke(Request $request, Supporters $supporters): Response
    {
        $expected = (string) config('services.kofi.token');
        $data = json_decode((string) $request->input('data'), true);

        if ($expected === '' || ! is_array($data) || ! hash_equals($expected, (string) ($data['verification_token'] ?? ''))) {
            return response('', 403);
        }

        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $messageId = (string) ($data['message_id'] ?? '');

        if (($data['type'] ?? '') !== 'Subscription' || ! ($data['is_subscription_payment'] ?? false) || $email === '' || $messageId === '') {
            return response('', 200);
        }

        DB::transaction(function () use ($data, $email, $messageId, $supporters): void {
            $user = User::whereRaw('lower(email) = ?', [$email])->first();

            $new = DB::table('kofi_payments')->insertOrIgnore([
                'message_id' => $messageId,
                'email' => $email,
                'tier_name' => $data['tier_name'] ?? null,
                'is_first' => (bool) ($data['is_first_subscription_payment'] ?? false),
                'user_id' => $user?->id,
                'received_at' => now(),
            ]);

            if ($new === 0) {
                return;
            }

            if ($user === null) {
                Log::warning('Ko-fi membership payment matched no account.', ['message_id' => $messageId]);

                return;
            }

            $supporters->grant($user, now()->addMonth());
        });

        return response('', 200);
    }
}
