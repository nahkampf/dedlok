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

    /** A drug that is not on the belt, not fight-only, or already used up. */
    case CannotUse = 'cannot_use';

    /** A consumable on the belt that would change nothing right now (a medkit at full health). */
    case NothingToDo = 'nothing_to_do';

    public function message(): string
    {
        return Text::get("combat.fight.refused.{$this->value}");
    }
}
