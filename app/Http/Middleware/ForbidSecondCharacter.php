<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Text;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The other half of the rule App\Http\Middleware\RequireCharacter states.
 *
 * That one says a player without a character may go nowhere but the creation
 * screen. This one says a player WITH one may not go back to it. Together they
 * make "exactly one character per user" a thing the router enforces rather
 * than a thing that happens to be true — `characters.user_id` is unique, so
 * without this the second submission is a 500 with an integrity error in the
 * log, and the creation form is a screen offering something it cannot do.
 *
 * Both routes, not just the form. Refusing the GET and leaving the POST open
 * would guard the door and not the room: a stale tab, a back button or a
 * hand-rolled request all reach `character.store` directly.
 *
 * One exception, and it is a read: an ADMIN with a character may still open
 * the creation screen. It is a screen they maintain — the archetype cards, the
 * loadouts, the size of the point pool — and the only way to see that work is
 * to look at it. Looking is all they get; the POST is refused for everyone who
 * already has a character, admin or not. When it lets an admin through this
 * way it says so on the request, so the controller can render the page as a
 * preview rather than asking the same question again.
 */
class ForbidSecondCharacter
{
    /**
     * Set on the request when an admin is being shown a screen they could not
     * otherwise reach. Read by CharacterCreationController, which renders the
     * page without its submit button; the attribute is the middleware's own
     * answer to "why are you allowed to see this", so nothing downstream has
     * to re-derive it.
     */
    public const PREVIEW = 'character.preview';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // exists() rather than loading the relation, for the same reason
        // RequireCharacter does it: one indexed lookup, no model.
        if ($user === null || ! $user->character()->exists()) {
            return $next($request);
        }

        // A safe method is a read, and reading is the whole exception.
        if ($request->isMethodSafe() && $user->can('admin')) {
            $request->attributes->set(self::PREVIEW, true);

            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => Text::get('character.create.already'),
                'redirect' => route('home'),
            ], 409);
        }

        $redirect = redirect()->route('home');

        /*
         * A GET here is a bookmark or a back button, and being quietly put
         * back where you belong is the whole answer. A write is different: a
         * silent redirect after a submitted form reads as the form having done
         * nothing, which is exactly what it did — so that one says why.
         */
        return $request->isMethodSafe()
            ? $redirect
            : $redirect->with('error', Text::get('character.create.already'));
    }
}
