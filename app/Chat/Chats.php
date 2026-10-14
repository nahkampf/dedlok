<?php

declare(strict_types=1);

namespace App\Chat;

use App\Events\ChatLine;
use App\Models\Character;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Notification;
use App\Models\User;
use App\Notifications\Category;
use App\Notifications\Notifier;
use App\Support\PlainText;
use App\Support\Text;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * THE ONLY WRITER of `chats`, `chat_messages` and `chat_members`, and the one place that says who may see a chat.
 *
 * General is everybody's and has no member rows. A room or a direct line is visible to its members only — and a chat a
 * character cannot see is the same 404 as one that does not exist, so a guessed id learns nothing. Every line is reduced
 * to plain text before it is judged and stored (`PlainText`); markdown is interpreted when drawn (`Markup`), never here.
 */
final class Chats
{
    public function __construct(private readonly Notifier $notifier = new Notifier) {}

    public const BODY = 2000;

    public const NAME = 64;

    /** How many lines a chat shows. */
    public const SHOWN = 50;

    /** Minutes of silence after which somebody no longer counts as here. */
    public const ACTIVE_MINUTES = 30;

    public function general(): Chat
    {
        return Chat::query()->where('kind', Chat::GENERAL)->firstOrFail();
    }

    public function canSee(Character $who, Chat $chat): bool
    {
        return $chat->kind === Chat::GENERAL || $this->isMember($who, $chat);
    }

    /** The chat, if this character may see it; null otherwise (the controller's 404). */
    public function visible(Character $who, int $id): ?Chat
    {
        $chat = Chat::query()->find($id);

        return $chat !== null && $this->canSee($who, $chat) ? $chat : null;
    }

    /**
     * What a player typed in a tab: a command (`/invite`, `/leave`, `/ignore`, `/unignore`) or a line (`post()`).
     * Answers the chat to show next and, for a command that has nothing to draw, a sentence for a toast.
     *
     * @return array{chat: int, notice: ?string}
     *
     * @throws ChatRefused
     */
    public function say(Character $from, Chat $in, string $raw): array
    {
        $text = PlainText::clean($raw, multiline: true, verbatim: true);

        if (preg_match('/^\s*\/(invite|leave|ignore|unignore)(?:\s+(\S+))?\s*$/iu', $text, $m) === 1) {
            return $this->command($from, $in, strtolower($m[1]), $m[2] ?? '');
        }

        if (preg_match('/^\/(?!msg\b)[a-z]+\b/iu', $text) === 1) {
            throw new ChatRefused(Text::get('chat.errors.unknown_command'));
        }

        return ['chat' => $this->post($from, $in, $text)->chat_id, 'notice' => null];
    }

    /**
     * Say something. `/msg handle words` goes to a direct line instead of $in, whichever tab it was typed in.
     *
     * @throws ChatRefused
     */
    public function post(Character $from, Chat $in, string $raw): ChatMessage
    {
        $text = PlainText::clean($raw, multiline: true, verbatim: true);

        if (preg_match('/^\/msg(?:\s+(\S+))?(?:\s+(.+))?$/su', $text, $m) === 1) {
            $to = isset($m[1]) ? Character::query()->where('handle', $m[1])->first() : null;

            if ($to === null || ! isset($m[2])) {
                throw new ChatRefused(Text::get('chat.errors.msg_usage'));
            }

            if ($to->is($from)) {
                throw new ChatRefused(Text::get('chat.errors.yourself'));
            }

            $in = $this->direct($from, $to);
            $text = $m[2];
        }

        return $this->write($from, $in, $text);
    }

    public function direct(Character $a, Character $b): Chat
    {
        $ids = [$a->getKey(), $b->getKey()];
        sort($ids);
        $key = implode(':', $ids);

        $found = Chat::query()->where('direct_key', $key)->first();

        if ($found !== null) {
            return $found;
        }

        try {
            return DB::transaction(function () use ($key, $ids): Chat {
                $chat = Chat::query()->create(['kind' => Chat::DIRECT, 'direct_key' => $key]);

                foreach ($ids as $id) {
                    DB::table('chat_members')->insert(['chat_id' => $chat->getKey(), 'character_id' => $id]);
                }

                return $chat;
            });
        } catch (QueryException) {
            // Both sides sent at once: the unique key let one through.
            return Chat::query()->where('direct_key', $key)->firstOrFail();
        }
    }

    /** @throws ChatRefused */
    public function createRoom(Character $by, string $name, bool $public): Chat
    {
        $name = PlainText::clean($name);

        if ($name === '' || mb_strlen($name) > self::NAME) {
            throw new ChatRefused(Text::get('chat.errors.name', ['max' => self::NAME]));
        }

        return DB::transaction(function () use ($by, $name, $public): Chat {
            $chat = Chat::query()->create(['kind' => Chat::ROOM, 'name' => $name, 'is_public' => $public, 'created_by' => $by->getKey()]);
            DB::table('chat_members')->insert(['chat_id' => $chat->getKey(), 'character_id' => $by->getKey()]);

            return $chat;
        });
    }

    /** Anybody who finds a public room may join it. */
    public function join(Character $who, Chat $chat): void
    {
        if ($chat->kind !== Chat::ROOM || ! $chat->is_public) {
            throw new ChatRefused(Text::get('chat.errors.private'));
        }

        DB::table('chat_members')->insertOrIgnore([
            'chat_id' => $chat->getKey(), 'character_id' => $who->getKey(), 'last_read_id' => $this->newestIn($chat),
        ]);
    }

    /**
     * A member invites somebody into a room. Nothing happens to the invitee's membership: they get an inbox message
     * with Join and No thanks, and become a member only by answering it.
     *
     * @throws ChatRefused
     */
    public function invite(Character $by, Chat $chat, string $handle): void
    {
        $who = Character::query()->where('handle', PlainText::clean($handle))->first();

        if ($chat->kind !== Chat::ROOM || ! $this->isMember($by, $chat)) {
            throw new ChatRefused(Text::get('chat.errors.not_a_member'));
        }

        if ($who === null) {
            throw new ChatRefused(Text::get('chat.errors.no_such'));
        }

        if ($this->isMember($who, $chat)) {
            throw new ChatRefused(Text::get('chat.errors.already', ['handle' => $who->handle]));
        }

        // Somebody who ignores the inviter is not told, and the inviter cannot tell.
        if ($this->ignores($who, $by) || DB::table('chat_invites')->where('chat_id', $chat->getKey())->where('character_id', $who->getKey())->exists()) {
            return;
        }

        DB::table('chat_invites')->insert(['chat_id' => $chat->getKey(), 'character_id' => $who->getKey(), 'invited_by' => $by->getKey()]);
        $private = ! $chat->is_public && DB::table('chat_members')->where('chat_id', $chat->getKey())->count() === 1;
        $this->notifier->send($who, Category::Personal, $private ? 'chat_invite_private' : 'chat_invite', ['handle' => $by->handle, 'room' => (string) $chat->name], ['chat_invite' => $chat->getKey()]);
    }

    /** Join: the invitation is spent, they are a member, and the room is told. @throws ChatRefused */
    public function accept(Character $who, int $chatId): Chat
    {
        $chat = Chat::query()->find($chatId);

        if ($chat === null || ! DB::table('chat_invites')->where('chat_id', $chatId)->where('character_id', $who->getKey())->exists()) {
            throw new ChatRefused(Text::get('chat.errors.no_invite'));
        }

        DB::transaction(function () use ($chat, $who): void {
            DB::table('chat_invites')->where('chat_id', $chat->getKey())->where('character_id', $who->getKey())->delete();
            DB::table('chat_members')->insertOrIgnore(['chat_id' => $chat->getKey(), 'character_id' => $who->getKey(), 'last_read_id' => 0]);
            $this->write($who, $chat, Text::get('chat.joined', ['handle' => $who->handle]), event: true);
        });
        $this->forgetInvite($who, $chatId);

        return $chat;
    }

    /** No thanks: the invitation goes and nothing else does. */
    public function decline(Character $who, int $chatId): void
    {
        DB::table('chat_invites')->where('chat_id', $chatId)->where('character_id', $who->getKey())->delete();
        $this->forgetInvite($who, $chatId);
    }

    /** @return array{chat: int, notice: ?string} */
    private function command(Character $from, Chat $in, string $name, string $handle): array
    {
        if (in_array($name, ['invite', 'leave'], true) && $in->kind !== Chat::ROOM) {
            throw new ChatRefused(Text::get('chat.errors.rooms_only'));
        }

        if ($name === 'leave') {
            return $this->leave($from, $in);
        }

        if ($handle === '') {
            throw new ChatRefused(Text::get("chat.errors.{$name}_usage"));
        }

        if ($name === 'invite') {
            $this->invite($from, $in, $handle);

            return ['chat' => $in->getKey(), 'notice' => Text::get('chat.notices.invited', ['handle' => $handle])];
        }

        $who = Character::query()->where('handle', $handle)->first();

        if ($who === null) {
            throw new ChatRefused(Text::get('chat.errors.no_such'));
        }

        if ($who->is($from)) {
            throw new ChatRefused(Text::get('chat.errors.yourself'));
        }

        if ($name === 'ignore') {
            DB::table('chat_ignores')->insertOrIgnore(['character_id' => $from->getKey(), 'ignored_id' => $who->getKey()]);
        } else {
            DB::table('chat_ignores')->where('character_id', $from->getKey())->where('ignored_id', $who->getKey())->delete();
        }

        return ['chat' => $in->getKey(), 'notice' => Text::get("chat.notices.{$name}d", ['handle' => $who->handle])];
    }

    /** @return array{chat: int, notice: ?string} A room nobody is left in goes with its history. */
    private function leave(Character $who, Chat $room): array
    {
        DB::transaction(function () use ($who, $room): void {
            // Said while still a member, so the line is theirs to write; the others see it.
            $this->write($who, $room, Text::get('chat.left_line', ['handle' => $who->handle]), event: true);
            DB::table('chat_members')->where('chat_id', $room->getKey())->where('character_id', $who->getKey())->delete();

            if (! DB::table('chat_members')->where('chat_id', $room->getKey())->exists()) {
                $room->delete();
            }
        });

        return ['chat' => $this->general()->getKey(), 'notice' => Text::get('chat.notices.left', ['room' => (string) $room->name])];
    }

    /** @return list<int> Whose lines this character does not see; the page applies it to pushed lines. */
    public function ignoredIds(Character $who): array
    {
        return DB::table('chat_ignores')->where('character_id', $who->getKey())->pluck('ignored_id')->map(fn ($id): int => (int) $id)->all();
    }

    private function ignores(Character $who, Character $other): bool
    {
        return DB::table('chat_ignores')->where('character_id', $who->getKey())->where('ignored_id', $other->getKey())->exists();
    }

    /** The inbox message that carried an invitation has done its job. */
    private function forgetInvite(Character $who, int $chatId): void
    {
        if ($who->user === null) {
            return;
        }

        $ids = Notification::query()->where('user_id', $who->user_id)->where('details->chat_invite', $chatId)->pluck('id')->all();
        $this->notifier->delete($who->user, $ids);
    }

    /** Everything up to the newest line is read. */
    public function markRead(Character $who, Chat $chat): void
    {
        DB::table('chat_members')
            ->where('chat_id', $chat->getKey())->where('character_id', $who->getKey())
            ->update(['last_read_id' => $this->newestIn($chat)]);
    }

    /** The newest lines, oldest first, with who said them (and their account, for the avatar). */
    public function lines(Chat $chat, Character $viewer): Collection
    {
        return ChatMessage::query()->with('character.user')
            ->where('chat_id', $chat->getKey())
            ->whereNotIn('character_id', DB::table('chat_ignores')->where('character_id', $viewer->getKey())->select('ignored_id'))->orderByDesc('id')->limit(self::SHOWN)->get()->reverse()->values();
    }

    /**
     * What this character's tab row shows: General, then every room and direct line they are in, each with its unread
     * count and its name (a direct line is named by the other person).
     *
     * @return Collection<int, array{chat: Chat, name: string, unread: int}>
     */
    public function tabs(Character $who): Collection
    {
        $unread = $this->unreadByChat($who);
        $mine = Chat::query()->join('chat_members as m', 'm.chat_id', '=', 'chats.id')
            ->where('m.character_id', $who->getKey())->orderBy('chats.id')->get(['chats.*']);

        // A private room of exactly two is a conversation between two people, and reads as the other person, like a
        // direct line; one with more, or a public one, keeps its own name.
        $pairs = DB::table('chat_members')->whereIn('chat_id', $mine->where('kind', Chat::ROOM)->where('is_public', false)->pluck('id'))
            ->groupBy('chat_id')->havingRaw('count(*) = 2')->pluck('chat_id');

        $others = DB::table('chat_members as m')->join('characters as c', 'c.id', '=', 'm.character_id')
            ->whereIn('m.chat_id', $mine->where('kind', Chat::DIRECT)->pluck('id')->concat($pairs))
            ->where('m.character_id', '!=', $who->getKey())->pluck('c.handle', 'm.chat_id');

        $general = $this->general();

        return collect([['chat' => $general, 'name' => Text::get('chat.general'), 'unread' => 0]])
            ->concat($mine->map(fn (Chat $chat): array => [
                'chat' => $chat,
                'name' => isset($others[$chat->getKey()]) ? (string) $others[$chat->getKey()] : (string) $chat->name,
                'unread' => (int) ($unread[$chat->getKey()] ?? 0),
            ]));
    }

    /** Public rooms this character has not joined, for the browse list. */
    public function joinable(Character $who): Collection
    {
        return Chat::query()->where('kind', Chat::ROOM)->where('is_public', true)
            ->whereNotIn('id', DB::table('chat_members')->where('character_id', $who->getKey())->select('chat_id'))
            ->orderBy('name')->limit(20)->get();
    }

    /** Total unread outside General: the rail's number. */
    public function unreadFor(Character $who): int
    {
        return (int) $this->unreadByChat($who)->sum();
    }

    /** @return Collection<int, int> chat id => unread lines */
    public function unreadByChat(Character $who): Collection
    {
        return DB::table('chat_members as m')
            ->join('chat_messages as x', 'x.chat_id', '=', 'm.chat_id')
            ->where('m.character_id', $who->getKey())
            ->whereColumn('x.id', '>', 'm.last_read_id')
            ->where('x.character_id', '!=', $who->getKey())
            ->whereNotIn('x.character_id', DB::table('chat_ignores')->where('character_id', $who->getKey())->select('ignored_id'))
            ->groupBy('m.chat_id')
            ->selectRaw('m.chat_id, count(*) as n')
            ->pluck('n', 'm.chat_id');
    }

    /** Accounts with a character that did something in the last half hour. Cached for a minute. */
    public function activeCount(): int
    {
        return (int) Cache::remember('chat.active', 60, fn (): int => User::query()->whereHas('character')
            ->where('last_active_at', '>=', now()->subMinutes(self::ACTIVE_MINUTES))->count());
    }

    private function write(Character $from, Chat $chat, string $text, bool $event = false): ChatMessage
    {
        if (trim($text) === '') {
            throw new ChatRefused(Text::get('chat.errors.empty'));
        }

        if (mb_strlen($text) > self::BODY) {
            throw new ChatRefused(Text::get('chat.errors.long', ['max' => self::BODY]));
        }

        if (! $this->canSee($from, $chat)) {
            throw new ChatRefused(Text::get('chat.errors.not_a_member'));
        }

        $line = ChatMessage::query()->create(['chat_id' => $chat->getKey(), 'character_id' => $from->getKey(), 'message' => $text, 'is_event' => $event]);
        $this->markRead($from, $chat);

        // A socket that is down must never cost somebody their message: the page falls back to polling.
        try {
            event(new ChatLine($chat, $line));
        } catch (\Throwable $e) {
            report($e);
        }

        return $line;
    }

    private function isMember(Character $who, Chat $chat): bool
    {
        return DB::table('chat_members')->where('chat_id', $chat->getKey())->where('character_id', $who->getKey())->exists();
    }

    private function newestIn(Chat $chat): int
    {
        return (int) ChatMessage::query()->where('chat_id', $chat->getKey())->max('id');
    }
}
