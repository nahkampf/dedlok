<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Chat;
use App\Models\ChatMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Facades\DB;

/**
 * A line was said: pushed to the open chat pages (`routes/channels.php` says who may listen).
 *
 * General goes to its one channel; a room or direct line goes to each MEMBER's own channel, so a page learns of a
 * conversation it had no tab for yet. The line is drawn on the server (`chat._line`) and the page inserts that HTML,
 * so nothing is built in script from what somebody typed. Sent NOW, not queued: a chat line that arrives late is
 * a worse chat. Ignoring is the viewer's, applied by the page from `by`.
 */
final class ChatLine implements ShouldBroadcastNow
{
    public function __construct(public readonly Chat $chat, public readonly ChatMessage $line) {}

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        if ($this->chat->kind === Chat::GENERAL) {
            return [new PrivateChannel('chat.general')];
        }

        return DB::table('chat_members')->where('chat_id', $this->chat->getKey())->pluck('character_id')
            ->map(fn ($id): Channel => new PrivateChannel("chat-user.{$id}"))->all();
    }

    public function broadcastAs(): string
    {
        return 'line';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'chat' => $this->chat->getKey(),
            'id' => $this->line->getKey(),
            'by' => $this->line->character_id,
            'html' => view('chat._line', ['line' => $this->line->loadMissing('character.user')])->render(),
        ];
    }
}
