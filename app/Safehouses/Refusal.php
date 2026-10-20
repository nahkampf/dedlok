<?php

declare(strict_types=1);

namespace App\Safehouses;

use App\Support\Text;

/** Why a safehouse request did nothing. Every case is an ordinary thing to try. */
enum Refusal: string
{
    case JackedIn = 'jacked_in';
    case Fighting = 'fighting';
    case Busy = 'busy';
    case NotHere = 'not_here';
    case Unfamiliar = 'unfamiliar';
    case AlreadyYours = 'already_yours';
    case CannotAfford = 'cannot_afford';
    case NotOffered = 'not_offered';
    case Cooldown = 'cooldown';
    case NothingToDo = 'nothing_to_do';
    case NotResting = 'not_resting';
    case SimstimLimit = 'simstim_limit';
    case TooFull = 'too_full';
    case Wired = 'wired';
    case NoStorage = 'no_storage';
    case StorageFull = 'storage_full';
    case CannotStore = 'cannot_store';
    case NoRoom = 'no_room';
    case Identified = 'identified';
    case NotIdentified = 'not_identified';
    case NotInside = 'not_inside';
    case NotWatching = 'not_watching';

    public function message(): string
    {
        return Text::get("safehouse.refused.{$this->value}");
    }
}
