<?php

declare(strict_types=1);

namespace App\Models;

use App\City\Concealment;
use App\City\DiscoveryRule;
use App\City\Feature;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
 * @property bool|null $shop_items_exists
 * @property bool|null $clinic_services_exists
 * @property bool|null $surgery_exists
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

    /** @return HasMany<ShopItem, $this> */
    public function shopItems(): HasMany
    {
        return $this->hasMany(ShopItem::class);
    }

    /** @return HasOne<ClinicSurgery, $this> */
    public function surgery(): HasOne
    {
        return $this->hasOne(ClinicSurgery::class);
    }

    /** @return HasMany<LocationBuy, $this> */
    public function buys(): HasMany
    {
        return $this->hasMany(LocationBuy::class);
    }

    /** @return HasMany<ClinicOffer, $this> */
    public function clinicServices(): HasMany
    {
        return $this->hasMany(ClinicOffer::class);
    }

    /**
     * What this place can do: it has the feature when it has the rows. Reads the
     * `withExists` columns when the query asked for them, so a map of pins does
     * not run two queries a pin.
     *
     * @return list<Feature>
     */
    public function features(): array
    {
        return array_values(array_filter([
            ($this->shop_items_exists ?? $this->shopItems()->exists()) ? Feature::Shop : null,
            ($this->clinic_services_exists ?? $this->clinicServices()->exists()) || ($this->surgery_exists ?? $this->surgery()->exists()) ? Feature::Clinic : null,
        ]));
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
