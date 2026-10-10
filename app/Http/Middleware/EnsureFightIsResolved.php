<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Text;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A player in a fight goes back to it, whatever they asked for.
 *
 * `EnsureHuntIsResolved`'s own shape, said about a different fight: a state that owns the screen,
 * with a named allow-list and nothing else. A fight is not an encounter but sits INSIDE one, so this
 * stands before the encounter lock — the fight is the narrower thing, and settling it is what lets
 * the encounter resume. Admin routes are NOT exempt: an admin who could edit the item they are
 * fighting with could edit their way out of one.
 *
 * It comes after `EnsureCharacterIsStanding`, because somebody on the floor is in no position to
 * take a turn and the two should never overlap.
 */
class EnsureFightIsResolved
{
    /**
     * Routes reachable while a fight is live.
     *
     * @var list<string>
     */
    private const ALLOWED_ROUTES = [
        'login',
        'logout',
        'fight.show',
        'fight.act',
        // The inbox's badge and sound poll from every page.
        'notifications.unread',
        // Muting is the account's, and a player under a lock is exactly who wants it.
        'sound.update',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $character = $request->user()?->character;

        if ($character === null || $character->current_fight_id === null) {
            return $next($request);
        }

        $name = $request->route()?->getName();

        if ($name !== null && in_array($name, self::ALLOWED_ROUTES, true)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => Text::get('combat.fight.locked'),
                'redirect' => route('fight.show'),
            ], 409);
        }

        return redirect()->route('fight.show');
    }
}
