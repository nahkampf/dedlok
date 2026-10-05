<?php

declare(strict_types=1);

namespace App\Combat;

use App\Support\Text;

/** Why a magazine was not filled or emptied. Every case is an ordinary thing a player can try. */
enum LoadRefusal: string
{
    case NotOwned = 'not_owned';

    /** Not a firearm: a blade has no magazine and a thrown explosive no calibre. */
    case NotAFirearm = 'not_a_firearm';

    /** In a container, fitted, running, installed or listed: it has to be in the hands first. */
    case NotFree = 'not_free';

    case NotAmmunition = 'not_ammunition';

    case WrongCalibre = 'wrong_calibre';

    /** None of that round is loose in the stash. */
    case NoAmmunition = 'no_ammunition';

    /** The same round, and the magazine is already full. */
    case Full = 'full';

    case NothingLoaded = 'nothing_loaded';

    public function message(): string
    {
        return Text::get("combat.loading.refused.{$this->value}");
    }
}
