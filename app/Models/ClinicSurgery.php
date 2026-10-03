<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A clinic's surgery: how good, how high a tier, what it charges, where it will cut.
 *
 * @property int $location_id
 * @property int $rating
 * @property int $install_price
 * @property int $removal_price
 */
#[Fillable(['location_id', 'rating', 'install_price', 'removal_price'])]
class ClinicSurgery extends Model
{
    protected $primaryKey = 'location_id';

    public $incrementing = false;
}
