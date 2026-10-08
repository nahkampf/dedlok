<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One build in progress. Written only by App\Crafting\Crafting.
 *
 * @property int $character_id
 * @property int $product_item_id
 * @property string $name
 * @property int $required_seconds
 * @property int $progress_seconds
 * @property CarbonImmutable $settled_at
 * @property CarbonImmutable|null $finished_at
 */
#[Fillable(['character_id', 'product_item_id', 'name', 'required_seconds', 'progress_seconds', 'settled_at', 'finished_at'])]
class Craft extends Model
{
    protected function casts(): array
    {
        return [
            'required_seconds' => 'integer', 'progress_seconds' => 'integer',
            'settled_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Character, $this> */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    /** Working time still to do, never negative. */
    public function remainingSeconds(): int
    {
        return max(0, $this->required_seconds - $this->progress_seconds);
    }

    public function isFinished(): bool
    {
        return $this->finished_at !== null;
    }
}
