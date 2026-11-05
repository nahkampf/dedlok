<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One component a Procure job wants, from systems one owner holds. The owner's name is copied so the card still reads after a rename.
 */
#[Fillable(['mission_id', 'component_item_id', 'quantity', 'owner_id', 'owner_name'])]
class MissionRequirement extends Model
{
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'owner_id' => 'integer'];
    }

    /** @return BelongsTo<Mission, $this> */
    public function mission(): BelongsTo
    {
        return $this->belongsTo(Mission::class);
    }

    /** @return BelongsTo<Item, $this> */
    public function component(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'component_item_id');
    }
}
