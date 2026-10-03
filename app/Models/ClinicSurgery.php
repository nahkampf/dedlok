<?php

declare(strict_types=1);

namespace App\Models;

use App\Implants\BodyRegion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A clinic's surgery: how good, how high a tier, what it charges, where it will cut.
 *
 * @property int $location_id
 * @property int $rating
 * @property int $max_tier
 * @property int $install_price
 * @property int $removal_price
 */
#[Fillable(['location_id', 'rating', 'max_tier', 'install_price', 'removal_price'])]
class ClinicSurgery extends Model
{
    protected $primaryKey = 'location_id';

    public $incrementing = false;

    /** @return list<BodyRegion> */
    public function regions(): array
    {
        return DB::table('clinic_surgery_regions')->where('location_id', $this->location_id)->pluck('region')
            ->map(static fn (string $r): ?BodyRegion => BodyRegion::tryFrom($r))->filter()->values()->all();
    }
}
