<?php

declare(strict_types=1);

namespace App\Combat;

use App\Support\Text;

/** Why nothing was put on or taken off. Every case is an ordinary thing a player can try. */
enum WearRefusal: string
{
    /** Somebody else's. */
    case NotOwned = 'not_owned';

    /** Not armour, or armour that covers nothing any more. */
    case NotArmour = 'not_armour';

    /** Inside a container, fitted, running or installed: it has to be in the hands first. */
    case NotFree = 'not_free';

    /** Already on. */
    case AlreadyWorn = 'already_worn';

    /** Not on, so there is nothing to take off. */
    case NotWorn = 'not_worn';

    public function message(): string
    {
        return Text::get("combat.armour.refused.{$this->value}");
    }
}
