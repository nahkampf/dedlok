<?php

declare(strict_types=1);

namespace App\Models;

use App\City\OutcomeBranch;
use App\City\OutcomeKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One effect of a choice. Applied only by `App\City\EncounterOutcomes`. */
#[Fillable(['choice_id', 'node_id', 'branch', 'position', 'kind', 'amount', 'ref', 'item_id', 'entity_id'])]
class EncounterOutcome extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'branch' => OutcomeBranch::class,
            'kind' => OutcomeKind::class,
            'amount' => 'float',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return BelongsTo<Entity, $this> */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }
}
