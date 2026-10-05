<?php

declare(strict_types=1);

namespace App\Models;

use App\Combat\WeaponClass;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The weapon half of an item. Written only by `App\Combat\WeaponEditor`.
 *
 * `damage` is ONE fixed figure and the profile says how it is split across damage types. Nothing
 * here is a range. **A firearm (one with a calibre) has neither**: its round carries both, so
 * both are NULL on it. A blade, a club or a thrown explosive has no round and keeps them here.
 */
#[Fillable(['item_id', 'class', 'damage', 'damage_profile', 'riders', 'accuracy', 'rate', 'magazine', 'calibre'])]
class Weapon extends Model
{
    protected function casts(): array
    {
        return [
            'class' => WeaponClass::class,
            'damage' => 'integer',
            'damage_profile' => 'array',
            'riders' => 'array',
            'accuracy' => 'integer',
            'rate' => 'integer',
            'magazine' => 'integer',
        ];
    }

    /** @return array<string, int> damage type => percent share, in the order stored; empty for a firearm */
    public function profile(): array
    {
        return array_map('intval', (array) $this->damage_profile);
    }

    /** @return list<array{kind: string, chance: int, turns: int, amount: int}> */
    public function riders(): array
    {
        return array_values((array) $this->riders);
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
