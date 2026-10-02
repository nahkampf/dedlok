<?php

declare(strict_types=1);

namespace App\Models;

use App\City\Concealment;
use App\City\DiscoveryRule;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * A place in a district.
 *
 * @property int $id
 * @property int $district_id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property int $map_x
 * @property int $map_y
 * @property DiscoveryRule $discovery
 * @property Concealment|null $concealment
 * @property string|null $required_flag
 */
#[Fillable(['district_id', 'slug', 'name', 'description', 'map_x', 'map_y', 'discovery', 'concealment', 'required_flag'])]
class Location extends Model
{
    protected function casts(): array
    {
        return [
            'map_x' => 'integer',
            'map_y' => 'integer',
            'discovery' => DiscoveryRule::class,
            'concealment' => Concealment::class,
        ];
    }

    /** @return BelongsTo<District, $this> */
    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    /**
     * What this character can see: THE ONE QUESTION, asked in one place.
     *
     * Open, or found (a `character_locations` row), or flagged (the required
     * flag is set). Visibility is a read and never a stored state, so a flag
     * needs no unlock step and cannot drift out of step with the location.
     * Nothing else may decide it.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, Character $character): void
    {
        $query->where(function (Builder $q) use ($character): void {
            $q->where('discovery', DiscoveryRule::Open->value)
                ->orWhere(function (Builder $q) use ($character): void {
                    $q->where('discovery', DiscoveryRule::Explorable->value)
                        ->whereIn('id', DB::table('character_locations')->select('location_id')->where('character_id', $character->getKey()));
                })
                ->orWhere(function (Builder $q) use ($character): void {
                    $q->where('discovery', DiscoveryRule::ExploredByFlag->value)
                        ->whereIn('required_flag', DB::table('character_flags')->select('flag')->where('character_id', $character->getKey()));
                });
        });
    }
}
