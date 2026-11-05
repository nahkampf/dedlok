<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The words of a text file an encounter left on somebody. Written by `EncounterOutcomes` only. */
#[Fillable(['inventory_item_id', 'title', 'body', 'ice'])]
class FileText extends Model
{
    /** The longest a text file may be, in characters: a bio's limit. */
    public const MAX = 65000;

    protected function casts(): array
    {
        return ['ice' => 'boolean'];
    }

    /** @return BelongsTo<InventoryItem, $this> */
    public function row(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}
