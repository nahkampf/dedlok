<?php

declare(strict_types=1);

namespace App\Combat;

use App\Support\Text;

/** Why a fight was not opened, or an action not taken. Every case is ordinary. */
enum FightRefusal: string
{
    /** Jacked in: a fight is in the City, and being in cyberspace is not. */
    case JackedIn = 'jacked_in';

    case InRun = 'in_run';

    case Hunted = 'hunted';

    case InFight = 'in_fight';

    /** On the floor: somebody unconscious is in no position to take a turn. */
    case Down = 'down';

    /** Travelling, exploring or under the knife: a trip would settle under a fight. */
    case Busy = 'busy';

    /** No live fight to act in. */
    case NoFight = 'no_fight';

    public function message(): string
    {
        return Text::get("combat.fight.refused.{$this->value}");
    }
}
