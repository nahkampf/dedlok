<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One screen of an encounter, reached by `node_key` and never by id. */
#[Fillable(['encounter_id', 'node_key', 'body', 'entry', 'ends'])]
class EncounterNode extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['entry' => 'boolean', 'ends' => 'boolean'];
    }

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /**
     * What happens on ARRIVING here, in order. The same rows as a choice's outcomes, owned by the
     * screen instead (`node_id`); they all apply, every time the screen is reached.
     *
     * @return HasMany<EncounterOutcome, $this>
     */
    public function outcomes(): HasMany
    {
        return $this->hasMany(EncounterOutcome::class, 'node_id')->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<EncounterChoice, $this> */
    public function choices(): HasMany
    {
        return $this->hasMany(EncounterChoice::class, 'node_id')->orderBy('position')->orderBy('id');
    }
}
