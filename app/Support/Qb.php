<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Storage, memory (QB) and compute (qFLOPS) are carried to ONE DECIMAL PLACE.
 *
 * The columns are DECIMAL(…,1) and PHP holds them as floats, which means a sum of tenths is not exactly a
 * sum of tenths: 0.1 + 0.2 is 0.30000000000000004, and a deck sitting exactly at its limit would refuse
 * or accept by luck. So the rule is two moves, and every place that totals or compares these goes through them:
 *
 * - `of()` rounds a total back to one decimal, so what is stored, shown and summed is what was meant;
 * - `tenths()` turns a figure into whole tenths, so "does it fit" is an integer comparison.
 */
final class Qb
{
    public static function of(float|int|string|null $value): float
    {
        return round((float) $value, 1);
    }

    /** The figure in whole tenths: 0.4 is 4. */
    public static function tenths(float|int|string|null $value): int
    {
        return (int) round((float) $value * 10);
    }

    /** @param  iterable<float|int|string|null>  $values */
    public static function sum(iterable $values): float
    {
        $tenths = 0;

        foreach ($values as $value) {
            $tenths += self::tenths($value);
        }

        return $tenths / 10;
    }

    /** `$used + $extra` still within `$capacity`. */
    public static function fits(float|int $used, float|int $extra, float|int $capacity): bool
    {
        return self::tenths($used) + self::tenths($extra) <= self::tenths($capacity);
    }

    /** `2` for 2.0 and `0.4` for 0.4. */
    public static function format(float|int|string|null $value, string $thousands = ','): string
    {
        $text = number_format(self::of($value), 1, '.', $thousands);

        return str_ends_with($text, '.0') ? substr($text, 0, -2) : $text;
    }
}
