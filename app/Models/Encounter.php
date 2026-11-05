<?php

declare(strict_types=1);

namespace App\Models;

use App\City\EncounterStart;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A narrative encounter definition. Written by `App\City\EncounterEditor` and read
 * by `App\City\Encounters`; see docs/CITY.md, *Encounters*.
 */
#[Fillable(['slug', 'title', 'intro', 'starts_on', 'weight', 'chance', 'district_id', 'location_id', 'system_type', 'grade_min', 'grade_max', 'owner_entity_id', 'run_purpose', 'run_outcome', 'spot_system_id', 'cooldown_hours', 'required_flag', 'once', 'retired'])]
class Encounter extends Model
{
    protected function casts(): array
    {
        return [
            'starts_on' => EncounterStart::class,
            'weight' => 'integer',
            'chance' => 'float',
            'once' => 'boolean',
            'retired' => 'boolean',
        ];
    }

    /**
     * The flag a `once` encounter sets on entry, and the draw checks.
     *
     * A slug may carry a hyphen and a flag may not (`Flags::set()`), so it is written as an underscore.
     */
    public function seenFlag(): string
    {
        return 'encounter_'.str_replace('-', '_', $this->slug);
    }

    /** @return HasMany<EncounterPrerequisite, $this> */
    public function prerequisites(): HasMany
    {
        return $this->hasMany(EncounterPrerequisite::class)->orderBy('position');
    }

    /** @return HasMany<EncounterNode, $this> */
    public function nodes(): HasMany
    {
        return $this->hasMany(EncounterNode::class);
    }

    /** @return BelongsTo<District, $this> */
    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
