<?php

declare(strict_types=1);

namespace App\Models;

use App\City\PrerequisiteKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One condition on an encounter. Written by `App\City\EncounterEditor` and read by `App\City\PrerequisiteJudge`.
 *
 * @property PrerequisiteKind $kind
 */
#[Fillable(['encounter_id', 'position', 'kind', 'subject', 'amount', 'item_id', 'entity_id', 'negate'])]
class EncounterPrerequisite extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['kind' => PrerequisiteKind::class, 'amount' => 'float', 'negate' => 'boolean', 'position' => 'integer'];
    }

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }
}
