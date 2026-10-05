<?php

declare(strict_types=1);

namespace App\Combat\Fight;

/** What a side can do with its turn. COMBAT.md, *Actions*. */
enum ActionKind: string
{
    case Attack = 'attack';

    /** Put rounds into the ranged weapon: when its magazine is empty, or to change type. */
    case Load = 'load';

    /** At Close: a chance to put the range back to Ranged for the next round. */
    case CreateDistance = 'create_distance';

    /** A chance to get away. Always offered, never certain; a failure spends the turn. */
    case Disengage = 'disengage';

    /** Spend the turn doing nothing: what a side with nothing usable at this range may do. */
    case Brace = 'brace';
}
