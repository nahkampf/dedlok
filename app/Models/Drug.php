<?php

declare(strict_types=1);

namespace App\Models;

use App\Drugs\Family;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The drug half of an item.
 *
 * Numbers only. What a dose DOES is `items.modifiers`, like an implant's —
 * this says which family it belongs to, how long it lasts, and what it costs
 * the two books.
 */
#[Fillable(['item_id', 'family', 'fight_only', 'duration_minutes', 'duration_turns', 'turn_hitpoints', 'addictive', 'tolerance_gain', 'lethal_at'])]
class Drug extends Model
{
    protected function casts(): array
    {
        return [
            'family' => Family::class,
            'fight_only' => 'boolean',
            'duration_minutes' => 'integer',
            'duration_turns' => 'integer',
            'turn_hitpoints' => 'integer',
            'addictive' => 'float',
            'tolerance_gain' => 'float',
            'lethal_at' => 'integer',
        ];
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
