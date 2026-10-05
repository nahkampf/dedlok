<?php

declare(strict_types=1);

namespace App\Models;

use App\Combat\AmmoType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The ammunition half of an item: what a firearm's hit IS. `damage` is the whole figure of a hit with
 * this round (an absolute base, not a percent) and the profile says how it splits across damage types;
 * the weapon that fires it carries neither. `penetration` and `riders` ride along as they always did.
 * Written only by `App\Combat\AmmunitionEditor`.
 */
#[Fillable(['item_id', 'calibre', 'type', 'damage', 'penetration', 'damage_profile', 'riders'])]
class Ammunition extends Model
{
    protected $table = 'ammunition';

    protected function casts(): array
    {
        return ['type' => AmmoType::class, 'damage' => 'integer', 'penetration' => 'integer', 'damage_profile' => 'array', 'riders' => 'array'];
    }

    /** @return array<string, int>|null how its damage splits across types; null only on a row that predates the rule */
    public function profile(): ?array
    {
        return $this->damage_profile === null ? null : array_map('intval', (array) $this->damage_profile);
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
