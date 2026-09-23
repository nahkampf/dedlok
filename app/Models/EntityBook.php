<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one entity thinks of one character, and what it owes them.
 *
 * Two running totals about one relationship, so one row rather than two
 * tables — the shape `character_drug_use` already has for addiction and
 * tolerance. A missing row is neutral and broke.
 *
 * `App\Standing\StandingLedger` is the only writer. Anything that moves these
 * numbers without writing the matching ledger entry breaks the invariant the
 * whole design rests on.
 */
#[Fillable(['character_id', 'entity_id', 'standing', 'loyalty'])]
class EntityBook extends Model
{
    protected $table = 'entity_books';

    protected function casts(): array
    {
        return [
            'standing' => 'float',
            'loyalty' => 'integer',
        ];
    }

    /** @return BelongsTo<Entity, $this> */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    /** @return BelongsTo<Character, $this> */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
