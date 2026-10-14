<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A conversation: General, a named room, or a direct line. `App\Chat\Chats` is the only writer.
 */
#[Fillable(['kind', 'name', 'is_public', 'created_by', 'direct_key'])]
class Chat extends Model
{
    public const GENERAL = 'general';

    public const ROOM = 'room';

    public const DIRECT = 'direct';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['is_public' => 'boolean'];
    }
}
