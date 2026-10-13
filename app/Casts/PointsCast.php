<?php

declare(strict_types=1);

namespace App\Casts;

use App\Support\Points;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A resource column (hit points, Synaptic Integrity): two decimals on the way in and out, a whole figure as an
 * `int` and a fractional one as a `float` — `Points::of()`. NULL stays NULL (not yet initialised).
 *
 * @implements CastsAttributes<int|float|null, int|float|string|null>
 */
final class PointsCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): int|float|null
    {
        return $value === null ? null : Points::of($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): int|float|string|null
    {
        return $value === null ? null : Points::of($value);
    }
}
