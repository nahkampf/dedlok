<?php

declare(strict_types=1);

namespace App\City;

use App\Support\Text;

/** How somebody travels. *Walk* is the free way, never the name of the act. */
enum TravelMode: string
{
    case Walk = 'walk';
    case Pod = 'pod';

    public function label(): string
    {
        return Text::get("city.mode.{$this->value}");
    }
}
