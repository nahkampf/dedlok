<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Text;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A player in an encounter goes back to it, whatever they asked for.
 *
 * `EnsureHuntIsResolved`'s shape: a named allow-list, a 409 and a URL for a JSON
 * caller, admin routes NOT exempt (an admin could otherwise edit their way out of
 * one). Encounters wait for the player — one that opened while they were away holds
 * the screen on their return, and nothing happens to them meanwhile.
 *
 * Ordered AFTER the waiting-room check, so somebody unconscious is shown the floor.
 */
class EnsureEncounterIsResolved
{
    /** @var list<string> */
    private const ALLOWED_ROUTES = ['login', 'logout', 'encounter.show', 'encounter.choose', 'encounter.leave', 'notifications.unread', 'sound.update', 'chat.show', 'chat.poll', 'chat.say', 'chat.rooms', 'chat.join', 'chat.invite', 'chat.accept', 'chat.decline', 'broadcasting.auth'];

    public function handle(Request $request, Closure $next): Response
    {
        $character = $request->user()?->character;

        if ($character === null || $character->current_encounter_id === null) {
            return $next($request);
        }

        // A fight started by this encounter is live: the fight lock, which runs before this one, has already
        // decided what is reachable. Redirecting to the encounter screen here would bounce a fighter between
        // the two locks for ever, because the fight lock sends that screen straight back.
        if ($character->current_fight_id !== null) {
            return $next($request);
        }

        $name = $request->route()?->getName();

        if ($name !== null && in_array($name, self::ALLOWED_ROUTES, true)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => Text::get('city.encounter.locked'),
                'redirect' => route('encounter.show'),
            ], 409);
        }

        $redirect = redirect()->route('encounter.show');

        // Said for something PRESSED (Stop, Abandon, a form): a silent redirect reads as a button that does nothing.
        // Plain navigation is not nagged, the screen it lands on is the explanation.
        return $request->isMethodSafe() ? $redirect : $redirect->with('error', Text::get('city.encounter.locked'));
    }
}
