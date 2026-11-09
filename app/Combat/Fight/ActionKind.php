<?php

declare(strict_types=1);

namespace App\Combat\Fight;

/** What a side can do with its turn. docs/COMBAT.md, *Actions*. */
enum ActionKind: string
{
    case Attack = 'attack';

    /** Put rounds into the ranged weapon: when its magazine is empty, or to change type. */
    case Load = 'load';

    /** A chance to win one or two steps of distance, up to the fight's maximum. A failure spends the turn. */
    case CreateDistance = 'create_distance';

    /** A chance to close the distance by one step. A failure spends the turn. */
    case CloseIn = 'close_in';

    /** A chance to get away, but only from beyond the fleeing distance. A failure spends the turn. */
    case Disengage = 'disengage';

    /** Spend the turn doing nothing: what a side with nothing usable at this range may do. */
    case Brace = 'brace';

    /** A belted fight-only drug (the row id is `round`). The turn is spent here; `Fights` takes the dose. */
    case Use = 'use';

    /** A bare-hands blow at Close, whatever is in the hand: a little harder, and it may hurt the giver. */
    case Headbutt = 'headbutt';

    /** A check to put somebody on the ground at Close: they lose their next turn and you get the upper hand. */
    case Takedown = 'takedown';
}
