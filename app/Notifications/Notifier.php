<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Character;
use App\Models\Notification;
use App\Models\User;
use App\Support\Text;

/**
 * The only writer of `notifications`, and the only thing that marks one read.
 *
 * ## The caller says what happened; this decides nothing
 *
 * `send()` takes a category and a STRING KEY, renders it once, and stores the
 * sentence. So a call site says "a read finished, here is the machine" and
 * never holds a sentence — which is the same division `Achievements::record()`
 * draws, and what keeps player-visible text in `config/strings.php` where the
 * rest of it lives.
 *
 * ## Rendered at write time, and never again
 *
 * The body is the sentence as it was true at that moment. Recomposing it later
 * would mean holding on to the machine, the skill or the level it names — all
 * of which are allowed to move, be renamed or be deleted — and would turn an
 * inbox into a set of joins that can each fail. A message survives its own
 * subject; see the migration.
 *
 * ## Addressed to the ACCOUNT, from either side
 *
 * Every sender in the game holds a Character and every inbox belongs to a
 * User, so this takes either and reaches through `characters.user_id` — a
 * column rather than a query. A character with no account is a test fixture
 * rather than a state the game can reach, and is silently nothing to notify.
 */
final class Notifier
{
    /** As much of a sentence as the column holds. */
    private const MAX_BODY = 500;

    /**
     * Write one message into somebody's inbox.
     *
     * Returns null when there was nobody to write it to, which is not an error
     * — the same answer `Achievements::move()` gives for the same fixture.
     */
    public function send(Character|User $who, Category $category, string $key, array $replace = []): ?Notification
    {
        $userId = $who instanceof User ? $who->getKey() : $who->user_id;

        if ($userId === null) {
            return null;
        }

        return Notification::create([
            'user_id' => (int) $userId,
            'category' => $category,
            // Truncated rather than refused: a message too long for the column
            // is a copy problem, and losing the whole notification over one
            // would be a worse answer than a clipped sentence.
            'body' => mb_substr(Text::get("notifications.messages.{$key}", $replace), 0, self::MAX_BODY),
        ]);
    }

    /**
     * Everything in this inbox has been seen.
     *
     * ALL OF THEM AT ONCE, because the alternative is a button per row for a
     * state nothing reads except a count. What being read means here is only
     * that the rail stops counting it; the message itself stays where it is
     * until somebody clears it.
     */
    public function markAllRead(User $user): int
    {
        return Notification::query()
            ->where('user_id', $user->getKey())
            ->unread()
            ->update(['read_at' => now()]);
    }

    /** What the rail's badge counts. */
    public function unreadFor(User $user): int
    {
        return Notification::query()
            ->where('user_id', $user->getKey())
            ->unread()
            ->count();
    }
}
