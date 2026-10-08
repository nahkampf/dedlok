<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a recipe: a quantity of a component, optionally of a particular origin (grade range, operating system, owner,
 * type of system, ICE and IDS level). A filter that is NULL accepts anything. Written only by App\Crafting\DesignEditor.
 *
 * @property int $design_id
 * @property int $position
 * @property int $component_item_id
 * @property int $quantity
 * @property int|null $grade_min
 * @property int|null $grade_max
 * @property string|null $os_slug
 * @property int|null $owner_id
 * @property string|null $system_type
 * @property int|null $ice_min
 * @property int|null $ids_min
 */
#[Fillable([
    'design_id', 'position', 'component_item_id', 'quantity',
    'grade_min', 'grade_max', 'os_slug', 'owner_id', 'system_type', 'ice_min', 'ids_min',
])]
class DesignComponent extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'position' => 'integer', 'quantity' => 'integer',
            'grade_min' => 'integer', 'grade_max' => 'integer', 'owner_id' => 'integer',
            'ice_min' => 'integer', 'ids_min' => 'integer',
        ];
    }

    /** @return BelongsTo<Design, $this> */
    public function design(): BelongsTo
    {
        return $this->belongsTo(Design::class);
    }

    /** @return BelongsTo<Item, $this> */
    public function component(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'component_item_id');
    }
}
