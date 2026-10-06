<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\User;
use App\Notifications\Notifier;
use App\Support\Text;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The inbox: what happened while nobody was looking.
 *
 * THE ACCOUNT'S, like the achievements page beside it in the rail — so nothing
 * here asks for a character, and a message outlives the one who caused it. It
 * still sits behind RequireCharacter, for the reason that page does: getting to
 * any screen at all means having a character.
 *
 * READING IS A SEPARATE ACT FROM OPENING. Marking everything read on the way
 * in would clear the badge for messages the player scrolled past without
 * seeing, and there is no cheaper way to be wrong about the one thing this
 * screen is for. So the button is a button.
 */
class NotificationController extends Controller
{
    /**
     * How many messages the screen shows at once.
     *
     * ponytail: newest N and no pager. An inbox nobody clears grows for ever
     * and this is the whole brake on the page it renders; when somebody has
     * scrolled past the hundredth, the fix is a pager or a "clear read" button
     * rather than a bigger number.
     */
    private const SHOWN = 100;

    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $messages = Notification::query()
            ->where('user_id', $user->getKey())
            ->newest()
            ->limit(self::SHOWN)
            ->get();

        return view('notifications.index', [
            'messages' => $messages,
            // Counted off the rows in hand rather than queried again: the
            // screen shows the newest hundred, and a badge that disagreed with
            // what is on the page would be the more confusing of the two.
            'unread' => $messages->whereNull('read_at')->count(),
        ]);
    }

    /**
     * Open one message: read it, then go wherever its category leads.
     *
     * OPENING ONE IS READING IT, which is not the contradiction of the rule
     * above that it looks like. What that rule refuses is clearing the badge
     * for messages nobody looked at — arriving on the screen is not reading
     * thirty of them, but clicking this row is reading this one.
     *
     * A POST and not a link, even though it navigates: it writes.
     */
    public function open(Request $request, Notification $message, Notifier $notifier): RedirectResponse
    {
        // Somebody else's message is a row that does not exist as far as this
        // player is concerned — the same call every other owned row makes.
        abort_unless((int) $message->user_id === (int) $request->user()->getKey(), 404);

        $notifier->markRead($message);

        return redirect()->route($message->category->route());
    }

    /** Read one without going anywhere: the inbox unfolding a row. 204, nothing to render. */
    public function mark(Request $request, Notification $message, Notifier $notifier): Response
    {
        abort_unless((int) $message->user_id === (int) $request->user()->getKey(), 404);

        $notifier->markRead($message);

        return response()->noContent();
    }

    public function destroy(Request $request, Notification $message, Notifier $notifier): RedirectResponse
    {
        abort_unless((int) $message->user_id === (int) $request->user()->getKey(), 404);

        $notifier->delete($request->user(), [$message->getKey()]);

        return back()->with('status', Text::plural('notifications.deleted', 1));
    }

    public function destroyMany(Request $request, Notifier $notifier): RedirectResponse
    {
        $ids = array_values(array_filter(array_map('intval', (array) $request->input('ids', []))));

        if ($ids === []) {
            return back()->with('error', Text::get('notifications.none_selected'));
        }

        return back()->with('status', Text::plural('notifications.deleted', $notifier->delete($request->user(), $ids)));
    }

    public function read(Request $request, Notifier $notifier): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $marked = $notifier->markAllRead($user);

        return back()->with('status', Text::plural('notifications.marked', $marked));
    }
}
