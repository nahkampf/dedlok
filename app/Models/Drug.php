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
#[Fillable(['item_id', 'family', 'duration_minutes', 'addictive', 'tolerance_gain'])]
class Drug extends Model
{
    protected function casts(): array
    {
        return [
            'family' => Family::class,
            'duration_minutes' => 'integer',
            'addictive' => 'float',
            'tolerance_gain' => 'float',
        ];
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
