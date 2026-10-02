<?php

declare(strict_types=1);

namespace App\City;

/** How a location becomes visible to a character. Stored on `locations.discovery`. */
enum DiscoveryRule: string
{
    /** Visible from the start. */
    case Open = 'open';

    /** Found by exploring. Needs a `Concealment`. */
    case Explorable = 'explorable';

    /** Visible once the character has `locations.required_flag`. */
    case ExploredByFlag = 'explored_by_flag';
}
