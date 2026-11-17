<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Bank\Loans;
use App\Support\Text;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A loan in default takes the screen as an incoming vidcall until the player answers it (docs/BANK.md).
 *
 * NEVER IN THE MIDDLE OF SOMETHING ELSE. The call waits while the character is in a run, a hunt, a fight, an
 * encounter, jacked in or down, and lands on the first request after. It does that itself rather than by where it
 * sits in the stack, because the run lock lets run routes through and would otherwise let the call hijack a live run.
 */
class EnsureDefaultIsFaced
{
    /** @var list<string> */
    private const ALLOWED_ROUTES = [
        'login', 'logout', 'sudo.exit', 'bank.call', 'bank.call.answer',
        'notifications.unread',
        'chat.show', 'chat.poll', 'chat.say', 'chat.rooms', 'chat.join', 'chat.invite', 'chat.accept', 'chat.decline', 'broadcasting.auth', 'chat.direct', 'characters.show',
        'sound.update',
    ];

    public function handle(Request $request, Closure $next, ?Loans $loans = null): Response
    {
        $character = $request->user()?->character;

        if ($character === null || $this->busy($character)) {
            return $next($request);
        }

        $name = $request->route()?->getName();

        if (($name !== null && in_array($name, self::ALLOWED_ROUTES, true)) || app(Loans::class)->pendingCall($character) === null) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => Text::get('bank.call.locked'), 'redirect' => route('bank.call')], 409);
        }

        $redirect = redirect()->route('bank.call');

        return $request->isMethodSafe() ? $redirect : $redirect->with('error', Text::get('bank.call.locked'));
    }

    private function busy(object $character): bool
    {
        return $character->current_run_id !== null || $character->current_hunt_id !== null || $character->current_fight_id !== null
            || $character->current_encounter_id !== null || $character->isJackedIn()
            || ($character->waiting_until !== null && $character->waiting_until->isFuture());
    }
}
