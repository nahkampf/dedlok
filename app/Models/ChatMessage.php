<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line said in a chat. Immutable, no `updated_at`; `App\Chat\Chats` is the only writer.
 */
#[Fillable(['chat_id', 'character_id', 'message'])]
class ChatMessage extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
