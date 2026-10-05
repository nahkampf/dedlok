<?php

declare(strict_types=1);

namespace App\Models;

use App\Combat\ArmourSlot;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One slot of one character, and the inventory row wearing it.
 *
 * Written by `App\Combat\ArmourWearer` and nothing else.
 */
#[Fillable(['character_id', 'slot', 'inventory_item_id'])]
class WornSlot extends Model
{
    protected $table = 'worn_armour';

    protected function casts(): array
    {
        return ['slot' => ArmourSlot::class];
    }

    /** @return BelongsTo<Character, $this> */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    /** @return BelongsTo<InventoryItem, $this> */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
