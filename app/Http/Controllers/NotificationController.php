<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\User;
use App\Notifications\Notifier;
use App\Support\Text;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function read(Request $request, Notifier $notifier): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $marked = $notifier->markAllRead($user);

        return back()->with('status', Text::plural('notifications.marked', $marked));
    }
}
