<?php

declare(strict_types=1);

namespace App\Chat;

use App\Models\Character;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
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
    public const BODY = 500;

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
     * Say something. `/msg handle words` goes to a direct line instead of $in, whichever tab it was typed in.
     *
     * @throws ChatRefused
     */
    public function post(Character $from, Chat $in, string $raw): ChatMessage
    {
        $text = PlainText::clean($raw, multiline: true);

        if (preg_match('/^\/msg(?:\s+(\S+))?(?:\s+(.+))?$/su', $text, $m) === 1) {
            $to = isset($m[1]) ? Character::query()->where('handle', $m[1])->first() : null;

            if ($to === null || ! isset($m[2])) {
                throw new ChatRefused(Text::get('chat.errors.msg_usage'));
            }

            if ($to->is($from)) {
                throw new ChatRefused(Text::get('chat.errors.yourself'));
            }

            $in = $this->direct($from, $to);
            $text = trim($m[2]);
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
     * A member invites somebody into a room. The invitation is a line in the room (so the invitee has something unread).
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

        DB::transaction(function () use ($by, $chat, $who): void {
            // Before the line, so the line counts as unread to the one it is about.
            DB::table('chat_members')->insert([
                'chat_id' => $chat->getKey(), 'character_id' => $who->getKey(), 'last_read_id' => $this->newestIn($chat),
            ]);
            $this->write($by, $chat, Text::get('chat.invited', ['handle' => $who->handle]));
        });
    }

    /** Everything up to the newest line is read. */
    public function markRead(Character $who, Chat $chat): void
    {
        DB::table('chat_members')
            ->where('chat_id', $chat->getKey())->where('character_id', $who->getKey())
            ->update(['last_read_id' => $this->newestIn($chat)]);
    }

    /** The newest lines, oldest first, with who said them (and their account, for the avatar). */
    public function lines(Chat $chat): Collection
    {
        return ChatMessage::query()->with('character.user')
            ->where('chat_id', $chat->getKey())->orderByDesc('id')->limit(self::SHOWN)->get()->reverse()->values();
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

        $others = DB::table('chat_members as m')->join('characters as c', 'c.id', '=', 'm.character_id')
            ->whereIn('m.chat_id', $mine->where('kind', Chat::DIRECT)->pluck('id'))
            ->where('m.character_id', '!=', $who->getKey())->pluck('c.handle', 'm.chat_id');

        $general = $this->general();

        return collect([['chat' => $general, 'name' => Text::get('chat.general'), 'unread' => 0]])
            ->concat($mine->map(fn (Chat $chat): array => [
                'chat' => $chat,
                'name' => $chat->kind === Chat::DIRECT ? (string) ($others[$chat->getKey()] ?? '?') : (string) $chat->name,
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

    private function write(Character $from, Chat $chat, string $text): ChatMessage
    {
        if ($text === '') {
            throw new ChatRefused(Text::get('chat.errors.empty'));
        }

        if (mb_strlen($text) > self::BODY) {
            throw new ChatRefused(Text::get('chat.errors.long', ['max' => self::BODY]));
        }

        if (! $this->canSee($from, $chat)) {
            throw new ChatRefused(Text::get('chat.errors.not_a_member'));
        }

        $line = ChatMessage::query()->create(['chat_id' => $chat->getKey(), 'character_id' => $from->getKey(), 'message' => $text]);
        $this->markRead($from, $chat);

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
