<?php

declare(strict_types=1);

namespace App\Cyberspace\Party;

use App\Support\Text;

enum PartyRefusal: string
{
    /** No coop_slots: nobody calls a co-op run without Coordinated Strike. */
    case NoSlots = 'no_slots';
    case NotJackedIn = 'not_jacked_in';
    case NothingThere = 'nothing_there';
    case TooNoisy = 'too_noisy';
    case InARun = 'in_a_run';
    case AlreadyInParty = 'already_in_party';
    case NoParty = 'no_party';
    case Full = 'full';
    case Yourself = 'yourself';
    case NoSuchRunner = 'no_such_runner';
    case NotInvited = 'not_invited';
    case NotAtTheDoor = 'not_at_the_door';
    case NotTheOwner = 'not_the_owner';
    case Started = 'started';

    public function message(): string
    {
        return Text::get("cyberspace.party.refused.{$this->value}");
    }
}
