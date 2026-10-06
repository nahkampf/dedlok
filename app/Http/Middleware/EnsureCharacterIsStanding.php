<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Characters\WaitingRoom;
use App\Support\Text;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Somebody on the floor goes to the waiting screen, whatever they asked for.
 *
 * ## It releases on the way past
 *
 * No scheduler and nothing to have been running: the minute ends the moment the
 * player next looks, which is the same lazy settlement a matured scan pass and
 * a training deposit both use. A player who never comes back never needs to be
 * got up.
 *
 * ## It runs BEFORE the run lock
 *
 * A defeat ends the run, ejects, and only then shows this screen — that is the
 * order every bad ending follows, and it means the two states should never
 * overlap. If they somehow do, being unconscious wins: a character on the floor
 * is not in a position to take a turn, and CYBERSPACE.md is explicit that
 * sitting on the waiting screen while still holding a position in cyberspace is
 * the one state nothing else knows how to read.
 */
class EnsureCharacterIsStanding
{
    /**
     * Routes reachable from the floor.
     *
     * @var list<string>
     */
    private const ALLOWED_ROUTES = [
        'login',
        'logout',
        'waiting.show',
        // The inbox's badge and sound poll from every page, this one included.
        'notifications.unread',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $character = $request->user()?->character;

        if ($character === null || $character->waiting_until === null) {
            return $next($request);
        }

        $room = app(WaitingRoom::class);

        // The minute is over the moment they look. Got up, and on their way.
        if ($room->release($character)) {
            return $next($request);
        }

        if ($this->isAllowed($request)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => Text::get('character.waiting.title'),
                'redirect' => route('waiting.show'),
            ], 409);
        }

        return redirect()->route('waiting.show');
    }

    private function isAllowed(Request $request): bool
    {
        $name = $request->route()?->getName();

        return $name !== null && in_array($name, self::ALLOWED_ROUTES, true);
    }
}
