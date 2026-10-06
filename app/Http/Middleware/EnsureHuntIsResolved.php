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

        return redirect()->route('hunt.show');
    }

    private function isAllowed(Request $request): bool
    {
        $name = $request->route()?->getName();

        return $name !== null && in_array($name, self::ALLOWED_ROUTES, true);
    }
}
