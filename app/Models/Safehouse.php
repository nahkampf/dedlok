<?php

declare(strict_types=1);

namespace App\Models;

use App\Characters\Meal;
use App\Safehouses\Action;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A kind of home, in one district. Authored content: the slug is permanent. FATIGUE.md, *Safehouses*.
 *
 * @property int $id
 * @property string $slug
 * @property string $title
 * @property string|null $description
 * @property int $district_id
 * @property int $rent
 * @property int $security
 * @property int $stealth
 * @property float $rest_rate
 * @property float $meal_fullness
 * @property float $meal_fatigue
 * @property int $storage_slots
 * @property list<string>|null $actions
 */
#[Fillable(['slug', 'title', 'description', 'district_id', 'rent', 'security', 'stealth', 'rest_rate', 'meal_fullness', 'meal_fatigue', 'storage_slots', 'actions'])]
class Safehouse extends Model
{
    protected function casts(): array
    {
        return [
            'district_id' => 'integer',
            'rent' => 'integer',
            'security' => 'integer',
            'stealth' => 'integer',
            'rest_rate' => 'float',
            'meal_fullness' => 'float',
            'meal_fatigue' => 'float',
            'storage_slots' => 'integer',
            'actions' => 'array',
        ];
    }

    /** @return BelongsTo<District, $this> */
    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    /** What a meal at this table is worth. */
    public function meal(): Meal
    {
        return new Meal($this->meal_fullness, $this->meal_fatigue);
    }

    /** Does this type offer the action? */
    public function offers(Action $action): bool
    {
        return in_array($action->value, (array) $this->actions, true);
    }

    /** The one everybody falls back to. */
    public static function fallback(): self
    {
        return self::query()->where('slug', (string) config('safehouse.fallback'))->firstOrFail();
    }
}
