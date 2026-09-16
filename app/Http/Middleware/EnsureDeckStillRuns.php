<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Cyberspace\ReadinessWatch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The net under `App\Cyberspace\ReadinessWatch`.
 *
 * The screens that can break a character's deck while they are standing in it
 * ask the watch themselves, in the same breath as the thing the player asked
 * for. This is what catches everything that cannot: a modifier that expired on
 * its own, a console command, anything written by something that has not
 * learned to ask.
 *
 * IT IS A NET AND NOT THE MECHANISM. A player who is not clicking stays in
 * cyberspace on a dead deck until they next do, which is the whole of what
 * this shape cannot fix — and it is why it is not the only caller. Anything
 * happening DURING a run resolves its own ending where it happens; leaving it
 * to the next request would leave somebody standing on the grid holding a
 * defeat.
 *
 * COSTS NOTHING WHEN NOBODY IS INSIDE. A character who is not jacked in is two
 * already-loaded columns and no query at all. One who is pays for a resolved
 * deck total per request, which is the price of the guarantee and is only paid
 * while they are in cyberspace.
 *
 * A CYBERSPACE REQUEST IS TURNED AWAY RATHER THAN LET THROUGH. The player has
 * just stopped being where they were asking about, and the controller's own
 * refusal — *you are not in cyberspace* — would be both true and useless,
 * replacing the message that explains why. Everything else carries on to the
 * page it asked for and finds out there.
 */
class EnsureDeckStillRuns
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $character = $user->character;

        // Nothing to watch. RequireCharacter has already sent a player with no
        // character to the creation screen, so null here is an admin.
        if ($character === null || ! $character->isJackedIn()) {
            return $next($request);
        }

        /*
         * THE NET STANDS DOWN INSIDE A RUN.
         *
         * A burnout is SUPPOSED to break the deck, and a Surge is supposed to
         * destroy what is fitted in it — both while somebody is standing in it,
         * and both resolve their own ending where they happen. If this fired
         * too, two things would be racing to end one run and the one that knows
         * least would usually win: this would eject for a flat point of
         * Synaptic Integrity and call it a readiness loss, throwing away the
         * defeat that actually occurred.
         *
         * The rule is the deck lock's, said about a different subject: the net
         * is for what happens to somebody standing still, never for what
         * happens to somebody in a fight.
         */
        if ($character->current_run_id !== null) {
            return $next($request);
        }

        $ejection = ReadinessWatch::fromConfig()->check($character);

        if ($ejection === null) {
            return $next($request);
        }

        if (! $this->isAboutCyberspace($request)) {
            $request->session()->flash('error', $ejection->message());

            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $ejection->message(),
                'redirect' => route('cyberspace.index'),
            ], 409);
        }

        return redirect()->route('cyberspace.index')->with('error', $ejection->message());
    }

    private function isAboutCyberspace(Request $request): bool
    {
        return $request->route()?->named('cyberspace.*') ?? false;
    }
}
