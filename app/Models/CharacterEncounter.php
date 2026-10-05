<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The encounter a character is in. Written by `App\City\Encounters` (opening) and
 * `App\City\EncounterRunner` (moving and closing) and nothing else.
 */
#[Fillable(['character_id', 'encounter_id', 'node_key', 'log', 'started_at'])]
class CharacterEncounter extends Model
{
    protected function casts(): array
    {
        return ['log' => 'array', 'started_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Character, $this> */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }
}
