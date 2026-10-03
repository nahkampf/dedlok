<?php

declare(strict_types=1);

namespace App\Models;

use App\City\ActivityKind;
use App\City\SurgeryOperation;
use App\City\TravelMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * What a character is doing in the City: one row at most. See `city_activities`.
 *
 * @property int $character_id
 * @property ActivityKind $kind
 * @property TravelMode|null $mode
 * @property SurgeryOperation|null $operation
 * @property int|null $subject_id
 * @property int|null $location_id
 * @property list<int>|null $route
 * @property int $step
 * @property int $paid
 * @property bool $cancelling
 * @property CarbonImmutable $pass_ends_at
 */
#[Fillable(['character_id', 'kind', 'mode', 'operation', 'subject_id', 'location_id', 'route', 'step', 'paid', 'cancelling', 'pass_ends_at'])]
class CityActivity extends Model
{
    protected $primaryKey = 'character_id';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'kind' => ActivityKind::class,
            'mode' => TravelMode::class,
            'operation' => SurgeryOperation::class,
            'route' => 'array',
            'step' => 'integer',
            'paid' => 'integer',
            'cancelling' => 'boolean',
            'pass_ends_at' => 'immutable_datetime',
        ];
    }
}
