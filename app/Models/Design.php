<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A recipe, pointed at by the design item a player holds (a Design Copy, spent by one craft, or a Design Original, kept).
 * An item IS a design because one of these points at it. Written only by App\Crafting\DesignEditor.
 *
 * @property int $item_id
 * @property int $product_item_id
 * @property int $craft_seconds
 * @property bool $single_use
 * @property int|null $skill_id
 * @property int $skill_level
 */
#[Fillable(['item_id', 'product_item_id', 'craft_seconds', 'single_use', 'skill_id', 'skill_level'])]
class Design extends Model
{
    protected function casts(): array
    {
        return ['craft_seconds' => 'integer', 'single_use' => 'boolean', 'skill_level' => 'integer'];
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return BelongsTo<Item, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'product_item_id');
    }

    /** @return BelongsTo<Skill, $this> */
    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    /** @return HasMany<DesignComponent, $this> */
    public function components(): HasMany
    {
        return $this->hasMany(DesignComponent::class)->orderBy('position');
    }
}
