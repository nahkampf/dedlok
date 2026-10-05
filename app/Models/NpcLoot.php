<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing an opponent may drop when it is beaten. Written only by `App\Combat\NpcEditor`, wholesale.
 *
 * @property int $id
 * @property int $npc_id
 * @property int $item_id
 * @property int $chance
 * @property int $min_quantity
 * @property int $max_quantity
 */
#[Fillable(['npc_id', 'item_id', 'chance', 'min_quantity', 'max_quantity'])]
class NpcLoot extends Model
{
    public $timestamps = false;

    protected $table = 'npc_loot';

    protected function casts(): array
    {
        return [
            'chance' => 'integer',
            'min_quantity' => 'integer',
            'max_quantity' => 'integer',
        ];
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
