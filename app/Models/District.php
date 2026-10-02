<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * A district of the City. Authored content: the slug is permanent.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property int $map_x
 * @property int $map_y
 * @property float $travel_weight
 */
#[Fillable(['slug', 'name', 'description', 'map_x', 'map_y', 'travel_weight'])]
class District extends Model
{
    protected function casts(): array
    {
        return [
            'map_x' => 'integer',
            'map_y' => 'integer',
            'travel_weight' => 'float',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return HasMany<Location, $this> */
    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    /** The id of the district a new character begins in, or null if it has been removed. */
    public static function startId(): ?int
    {
        $id = self::query()->where('slug', (string) Config::get('city.start'))->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Every link as a pair of district ids, lower first.
     *
     * @return list<array{0: int, 1: int}>
     */
    public static function links(): array
    {
        return DB::table('district_links')->get(['district_a_id', 'district_b_id'])
            ->map(static fn (object $row): array => [(int) $row->district_a_id, (int) $row->district_b_id])
            ->all();
    }
}
