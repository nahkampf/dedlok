<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
 | Who may listen. Both are private channels and the page asks `/broadcasting/auth` for each: General is any
 | character's, and `chat-user.{id}` is only that character's own — rooms and direct lines are delivered there, so
 | membership is judged when the line is SENT, never by a listener choosing a channel.
 */
Broadcast::channel('chat.general', fn (User $user): bool => $user->character !== null);

Broadcast::channel('chat-user.{id}', fn (User $user, string|int $id): bool => $user->character?->getKey() === (int) $id);
