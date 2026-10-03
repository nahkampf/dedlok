<?php

declare(strict_types=1);

namespace App\City;

use RuntimeException;

/** Thrown inside `Surgeon::buyAndFit()` to roll the purchase back, and caught there. */
final class RefusedUnderTheKnife extends RuntimeException
{
    public function __construct(public readonly CityRefusal $refusal)
    {
        parent::__construct($refusal->value);
    }
}
