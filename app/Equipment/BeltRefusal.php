<?php

declare(strict_types=1);

namespace App\Equipment;

use App\Support\Text;

/** Why nothing was put on or taken off the belt. Ordinary things a player can try. */
enum BeltRefusal: string
{
    case NotOwned = 'not_owned';

    /** Only drugs go on a belt, for now. */
    case NotBeltable = 'not_beltable';

    case AlreadyBelted = 'already_belted';

    case NotBelted = 'not_belted';

    /** Inside a container, fitted, running, installed or listed: it has to be in the hands first. */
    case NotFree = 'not_free';

    /** Every slot on the belt has something in it. */
    case BeltFull = 'belt_full';

    public function message(): string
    {
        return Text::get("equipment.belt.refused.{$this->value}");
    }
}
