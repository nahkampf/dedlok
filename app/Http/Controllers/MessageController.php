<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Character;
use App\Notifications\Notifier;
use App\Support\PlainText;
use App\Support\Text;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A message from one player to another: the inbox's "New message".
 *
 * It lands in the recipient's inbox as an ordinary notification (`Notifier::sendFrom()`), so it gets the badge, the
 * sound and the accordion for free. Everything typed is reduced to plain text before it is checked and stored
 * (`PlainText`), and the routes are throttled: this is the one place a player can make the game write something to
 * somebody else.
 */
class MessageController extends Controller
{
    /** Most characters a title may have, and the most a message may (the inbox column holds 500). */
    private const TITLE = 100;

    private const BODY = 500;

    public function create(Request $request): View
    {
        return view('notifications.compose', [
            // `?to=` is how a reply arrives. Cleaned like anything else typed.
            'to' => PlainText::clean((string) $request->query('to')),
            'max' => ['title' => self::TITLE, 'body' => self::BODY],
        ]);
    }

    public function store(Request $request, Notifier $notifier): RedirectResponse
    {
        $from = $request->user()->character;

        // Cleaned BEFORE it is judged, so "<b></b>" is an empty title and not a valid one.
        $request->merge([
            'to' => PlainText::clean((string) $request->input('to')),
            'title' => PlainText::clean((string) $request->input('title')),
            'message' => PlainText::clean((string) $request->input('message'), multiline: true),
        ]);

        $data = $request->validate([
            'to' => ['required', 'string', 'max:50'],
            'title' => ['required', 'string', 'max:'.self::TITLE],
            'message' => ['required', 'string', 'max:'.self::BODY],
        ], [
            'to.required' => Text::get('notifications.compose.errors.to'),
            'title.required' => Text::get('notifications.compose.errors.title'),
            'title.max' => Text::get('notifications.compose.errors.title_max', ['max' => self::TITLE]),
            'message.required' => Text::get('notifications.compose.errors.message'),
            'message.max' => Text::get('notifications.compose.errors.message_max', ['max' => self::BODY]),
        ]);

        $to = Character::query()->where('handle', $data['to'])->first();

        if ($to === null) {
            return back()->withInput()->withErrors(['to' => Text::get('notifications.compose.errors.no_such')]);
        }

        if ($to->is($from)) {
            return back()->withInput()->withErrors(['to' => Text::get('notifications.compose.errors.yourself')]);
        }

        $notifier->sendFrom($from, $to, $data['title'], $data['message']);

        return redirect()->route('notifications.index')->with('status', Text::get('notifications.compose.sent', ['handle' => $to->handle]));
    }

    /**
     * Handles that start with what has been typed, for the To field's suggestions. Handles only, nothing else about a
     * character, at most eight, never the sender's own, and nothing at all for a blank.
     */
    public function recipients(Request $request): JsonResponse
    {
        $typed = PlainText::clean((string) $request->query('q'));

        if ($typed === '') {
            return response()->json([]);
        }

        $like = addcslashes($typed, '%_\\').'%';

        return response()->json(
            Character::query()
                ->where('handle', 'like', $like)
                ->where('id', '!=', $request->user()->character->getKey())
                ->orderBy('handle')
                ->limit(8)
                ->pluck('handle')
                ->all(),
        );
    }
}
