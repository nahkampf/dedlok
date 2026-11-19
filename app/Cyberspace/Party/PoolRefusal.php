<?php

declare(strict_types=1);

namespace App\Cyberspace\Party;

use App\Support\Text;

enum PoolRefusal: string
{
    /** Not in this party, or it has already started or gone. */
    case NoParty = 'no_party';
    /** Nobody else has joined: there is nobody to claim anything. */
    case Alone = 'alone';
    /** The item is not one the pool takes, or is running, listed, stored or ticked for the run. */
    case NotEligible = 'not_eligible';
    /** Somebody else got there first, or it is already gone. */
    case Gone = 'gone';
    /** Your own offering is yours to return, not to claim. */
    case Yours = 'yours';
    /** The claimant's deck has no room for it. */
    case NoRoom = 'no_room';
    /** Only the one who offered it can withdraw it, and only the one who claimed it can put it back. */
    case NotYours = 'not_yours';
    /** A claimed item that has been used or moved cannot be put back. */
    case NotUnused = 'not_unused';

    public function message(): string
    {
        return Text::get("cyberspace.party.pool.refused.{$this->value}");
    }
}
