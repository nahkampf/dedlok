<?php

declare(strict_types=1);

namespace App\Models;

use App\Leaderboard\Board;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One ranked character on one board, as of the last snapshot. Written only by `App\Leaderboard\Snapshot`. */
#[Fillable(['board', 'character_id', 'value', 'rank', 'position', 'written_at'])]
class LeaderboardEntry extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'board' => Board::class,
            'value' => 'integer',
            'rank' => 'integer',
            'position' => 'integer',
            'written_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Character, $this> */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
