<?php

declare(strict_types=1);

namespace App\Models;

use App\Characters\Meal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One meal on a location's menu.
 *
 * @property int $id
 * @property int $location_id
 * @property string $name
 * @property int $price
 * @property float $meal_fullness
 * @property float $meal_fatigue
 */
#[Fillable(['location_id', 'name', 'price', 'meal_fullness', 'meal_fatigue'])]
class LocationMeal extends Model
{
    protected function casts(): array
    {
        return ['price' => 'integer', 'meal_fullness' => 'float', 'meal_fatigue' => 'float'];
    }

    public function meal(): Meal
    {
        return new Meal($this->meal_fullness, $this->meal_fatigue);
    }
}
