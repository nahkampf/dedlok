<?php

declare(strict_types=1);

namespace App\Models;

use App\Tasks\TaskPriority;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A task a player set for themselves. A reminder: nothing in the game reads it. */
#[Fillable(['character_id', 'title', 'priority', 'completed_at'])]
class PlayerTask extends Model
{
    protected function casts(): array
    {
        return ['priority' => TaskPriority::class, 'completed_at' => 'datetime'];
    }
}
