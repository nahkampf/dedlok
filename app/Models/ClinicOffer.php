<?php

declare(strict_types=1);

namespace App\Models;

use App\City\ClinicService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One treatment a clinic offers, per point.
 *
 * @property int $location_id
 * @property ClinicService $service
 * @property int $price
 */
#[Fillable(['location_id', 'service', 'price'])]
class ClinicOffer extends Model
{
    protected $table = 'clinic_services';

    protected function casts(): array
    {
        return ['service' => ClinicService::class, 'price' => 'integer'];
    }
}
