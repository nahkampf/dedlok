<?php

declare(strict_types=1);

namespace App\Models;

use App\Notifications\Category;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message in an account's inbox.
 *
 * A row, and nothing else: `App\Notifications\Notifier` is the only thing that
 * writes one and the only thing that marks one read, so there is no method
 * here that changes anything.
 */
#[Fillable(['user_id', 'category', 'body', 'read_at'])]
class Notification extends Model
{
    protected function casts(): array
    {
        return [
            'category' => Category::class,
            'read_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param  Builder<self>  $query */
    public function scopeUnread(Builder $query): void
    {
        $query->whereNull('read_at');
    }

    /**
     * Newest first, which is the only order an inbox has.
     *
     * By id rather than by `created_at`: several notifications can be written
     * in the same second — a deposit that catches up three levels writes
     * three — and a tie broken arbitrarily would shuffle them on every render.
     *
     * @param  Builder<self>  $query
     */
    public function scopeNewest(Builder $query): void
    {
        $query->orderByDesc('id');
    }
}
