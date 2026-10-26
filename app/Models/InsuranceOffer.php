<?php

declare(strict_types=1);

namespace App\Models;

use App\Insurance\InsuranceKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One policy a location sells.
 *
 * @property int $location_id
 * @property InsuranceKind $kind
 * @property int $share
 * @property int $price
 */
#[Fillable(['location_id', 'kind', 'share', 'price'])]
class InsuranceOffer extends Model
{
    protected function casts(): array
    {
        return ['kind' => InsuranceKind::class, 'share' => 'integer', 'price' => 'integer'];
    }
}
