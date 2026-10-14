<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Text;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A player fighting a hunter goes back to it, whatever they asked for.
 *
 * DELIBERATELY THE SHAPE OF `EnsureRunIsFinished`, because it is the same
 * idea said about a different fight: a player in a state that owns the
 * screen. Cyberspace combat was specified as having no door out until it is
 * settled — not egress, not jacking out — so unlike a run's lock, there is no
 * third route to leave through. Just the hunt itself.
 *
 * Admin routes are NOT exempt, for the same reason `EnsureRunIsFinished`
 * makes them not exempt: an admin who could reach the item editor mid-hunt
 * could edit their way out of one.
 */
class EnsureHuntIsResolved
{
    /**
     * Routes reachable while a hunt is live.
     *
     * @var list<string>
     */
    private const ALLOWED_ROUTES = [
        'login',
        'logout',
        'hunt.show',
        'hunt.act',
        // The inbox's badge and sound poll from every page.
        'notifications.unread',
        // The chat panel and everything it posts, for the same reason: somebody locked in a run can still talk.
        'chat.show', 'chat.poll', 'chat.say', 'chat.rooms', 'chat.join', 'chat.invite',
        // Muting is the account's, and a player under a lock is exactly who wants it.
        'sound.update',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $character = $request->user()?->character;

        if ($character === null || $character->current_hunt_id === null) {
            return $next($request);
        }

        if ($this->isAllowed($request)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => Text::get('cyberspace.hunt.locked'),
                'redirect' => route('hunt.show'),
            ], 409);
        }

        $redirect = redirect()->route('hunt.show');

        // Said for something PRESSED (Stop, Abandon, a form): a silent redirect reads as a button that does nothing.
        // Plain navigation is not nagged, the screen it lands on is the explanation.
        return $request->isMethodSafe() ? $redirect : $redirect->with('error', Text::get('cyberspace.hunt.locked'));
    }

    private function isAllowed(Request $request): bool
    {
        $name = $request->route()?->getName();

        return $name !== null && in_array($name, self::ALLOWED_ROUTES, true);
    }
}
