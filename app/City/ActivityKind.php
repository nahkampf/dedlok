<?php

declare(strict_types=1);

namespace App\City;

/** What a character is doing in the City. Stored on `city_activities.kind`. */
enum ActivityKind: string
{
    case Travel = 'travel';
    case Explore = 'explore';
}
