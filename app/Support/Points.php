<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Hit points and Synaptic Integrity are carried to TWO DECIMAL PLACES.
 *
 * Like `Qb`, and for the same reason: the columns are DECIMAL(8,2), PHP holds the figure as a number, and a sum of
 * hundredths is not exactly a sum of hundredths (10.1 - 0.2 is not 9.9). Everything that totals or compares them goes
 * through `of()`.
 *
 * **A whole figure is an `int` and a fractional one a `float`**, so a character on 30 is still `30` to every
 * `=== 0` and `assertSame(30, …)` that existed when these were whole, and a fraction is the only thing that ever
 * arrives as a float. `of()` is the one place that decides which.
 */
final class Points
{
    public static function of(float|int|string|null $value): int|float
    {
        $rounded = round((float) $value, 2);

        return $rounded === floor($rounded) ? (int) $rounded : $rounded;
    }

    /** `12` for 12.0, `0.5` for 0.5 and `7.25` for 7.25. */
    public static function format(float|int|string|null $value, string $thousands = ','): string
    {
        $text = number_format(round((float) $value, 2), 2, '.', $thousands);

        return preg_replace('/\.?0+$/', '', $text) ?? $text;
    }
}
