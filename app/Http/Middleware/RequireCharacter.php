<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Text;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A signed-in player without a character goes to character creation, whatever
 * they asked for.
 *
 * Registration deliberately creates only the user, so this is the gate that
 * makes "every user has a character" true in practice rather than by
 * convention.
 */
class RequireCharacter
{
    /**
     * Routes reachable without a character.
     *
     * The creation screen itself is obvious. Logging out has to stay reachable
     * or an account with no character is a trap, and the locale switch has to
     * work so the creation page can be read in the player's own language.
     * Fortify's own auth routes are excluded too: a guest hitting /login while
     * this middleware runs must not be bounced.
     *
     * @var list<string>
     */
    private const ALLOWED_ROUTES = [
        'character.create',
        'character.store',
        'handles.available',
        'locale.update',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        if ($this->isAllowed($request)) {
            return $next($request);
        }

        // exists() rather than loading the relation: this runs on every
        // authenticated request, and the answer is a single indexed lookup.
        if ($user->character()->exists()) {
            return $next($request);
        }

        // Never redirect a non-GET request into a GET page — the browser would
        // lose the payload and the player would see a silent no-op.
        if ($request->expectsJson()) {
            return response()->json([
                'message' => Text::get('character.create.title'),
                'redirect' => route('character.create'),
            ], 409);
        }

        return redirect()->route('character.create');
    }

    private function isAllowed(Request $request): bool
    {
        $name = $request->route()?->getName();

        return $name !== null && in_array($name, self::ALLOWED_ROUTES, true);
    }
}
