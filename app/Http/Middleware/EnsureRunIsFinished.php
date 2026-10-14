<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Text;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A player inside a run goes back to it, whatever they asked for.
 *
 * ## This is what replaces atomicity
 *
 * A run used to be resolved end to end in one request, and the thing that
 * bought was that pulling the plug could not save you. An interactive run a
 * player could walk out of is not a risk, it is a menu: close the tab when the
 * ICE lands a good hit, sign back in, and the bad run never happened.
 *
 * So **signing out does not end a run and does not pause its consequences — it
 * postpones a turn, and the turn is still there when you come back.** There is
 * nothing to escape to.
 *
 * ## Deliberately the shape of RequireCharacter
 *
 * One middleware, a named list of what is allowed through, everything else
 * redirected. Built as that shape rather than as a new idea, because it is the
 * same idea: a player in a state that owns the screen.
 *
 * ## What is allowed through, and what is not
 *
 * Signing in and out, so a session can end and resume at all. The run's own
 * routes, or the redirect would be a loop.
 *
 * **Admin routes are NOT exempt**, and that is the difference from
 * `RequireCharacter`. That middleware lets them through because the tools edit
 * the game rather than a character — but a run is a character in jeopardy, and
 * an admin who could reach the item editor mid-run could edit their way out of
 * one. The cost is that an admin cannot fix a wedged run from inside it, which
 * is a fair trade for the hole it closes.
 */
class EnsureRunIsFinished
{
    /**
     * Routes reachable while a run is live.
     *
     * @var list<string>
     */
    private const ALLOWED_ROUTES = [
        'login',
        'logout',
        'run.show',
        'run.act',
        'run.egress',
        // The inbox's badge and sound poll from every page.
        'notifications.unread',
        // The chat panel and everything it posts, for the same reason: somebody locked in a run can still talk.
        'chat.show', 'chat.poll', 'chat.say', 'chat.rooms', 'chat.join', 'chat.invite', 'chat.accept', 'chat.decline',
        // Muting is the account's, and a player under a lock is exactly who wants it.
        'sound.update',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $character = $request->user()?->character;

        if ($character === null || $character->current_run_id === null) {
            return $next($request);
        }

        // An encounter opened inside the run owns the screen (EnsureEncounterIsResolved runs first and holds the
        // player to it). Standing down is what stops the two locks sending each other's screen back.
        if ($character->current_encounter_id !== null) {
            return $next($request);
        }

        if ($this->isAllowed($request)) {
            return $next($request);
        }

        // A fetch() would follow a redirect into HTML and render it into a
        // panel. 409 and the URL is the answer both creation guards already
        // give, and for the same reason.
        if ($request->expectsJson()) {
            return response()->json([
                'message' => Text::get('cyberspace.run.locked'),
                'redirect' => route('run.show'),
            ], 409);
        }

        $redirect = redirect()->route('run.show');

        // Said for something PRESSED (Stop, Abandon, a form): a silent redirect reads as a button that does nothing.
        // Plain navigation is not nagged, the screen it lands on is the explanation.
        return $request->isMethodSafe() ? $redirect : $redirect->with('error', Text::get('cyberspace.run.locked'));
    }

    private function isAllowed(Request $request): bool
    {
        $name = $request->route()?->getName();

        return $name !== null && in_array($name, self::ALLOWED_ROUTES, true);
    }
}
