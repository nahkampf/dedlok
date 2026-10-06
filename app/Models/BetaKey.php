<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single-use code that lets a tester register while the alpha testing flag is on. Written only through
 * `App\Beta\BetaKeys`.
 */
#[Fillable(['code', 'given_out_at', 'user_id', 'used_at'])]
class BetaKey extends Model
{
    protected function casts(): array
    {
        return [
            'given_out_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
        ];
    }

    /** Spent: `used_at` is the fact, since the account behind it may have been deleted. */
    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
