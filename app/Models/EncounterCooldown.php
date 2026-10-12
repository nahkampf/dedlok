<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** When a character last opened a spot encounter. Written only by `App\City\Encounters`. */
#[Fillable(['character_id', 'encounter_id', 'last_opened_at'])]
class EncounterCooldown extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['last_opened_at' => 'immutable_datetime'];
    }
}
