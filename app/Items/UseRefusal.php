<?php

declare(strict_types=1);

namespace App\Items;

use App\Support\Text;

/** Why a copy was not used. Ordinary things a player can try, so an enum. */
enum UseRefusal: string
{
    case NotOwned = 'not_owned';

    /** Not a consumable, or one whose row has gone. */
    case NotUsable = 'not_usable';

    /** In a container, fitted, running or installed: it has to be in the hands first. */
    case NotFree = 'not_free';

    /** Using it would do nothing (a medkit at full health): refused, and the copy is kept. */
    case NothingToDo = 'nothing_to_do';

    /** Food, and they cannot eat any more. */
    case TooFull = 'too_full';

    /** A fight is live. What happens in a fight happens through the fight (docs/COMBAT.md: items mid-fight are later). */
    case InAFight = 'in_a_fight';

    public function message(): string
    {
        return Text::get("consumables.refused.{$this->value}");
    }
}
