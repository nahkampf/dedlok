<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One item an archetype starts you with, and how many of it.
 *
 * The same shape as a starting skill and for the same reason: a set, so a
 * table; a definition, so a foreign key. Deleting the item takes the grant
 * with it — an archetype cannot hand out something that no longer exists, and
 * App\Admin\DeletionImpact counts the loadouts a delete would quietly rewrite.
 *
 * What a character ends up carrying is an `inventory_items` row, written at
 * creation. Where it sits is not a question this row answers: a grant always
 * lands loose, because putting it inside a container would mean granting the
 * container too and deciding an order between them.
 */
#[Fillable(['archetype_id', 'item_id', 'quantity'])]
class ArchetypeItem extends Model
{
    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function archetype(): BelongsTo
    {
        return $this->belongsTo(Archetype::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    protected static function booted(): void
    {
        // See ArchetypeSkill: the memoised roster can be carrying these rows.
        static::saved(Archetype::forgetRoster(...));
        static::deleted(Archetype::forgetRoster(...));
    }
}
