<?php

declare(strict_types=1);

namespace App\Combat\Fight;

/** How far apart the sides are. A fight opens at Ranged and closes after a round. COMBAT.md. */
enum Range: string
{
    case Ranged = 'ranged';
    case Close = 'close';
}
