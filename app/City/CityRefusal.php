<?php

declare(strict_types=1);

namespace App\City;

use App\Support\Text;

/** Why nothing started. Every case is an ordinary thing a player can try. */
enum CityRefusal: string
{
    /** Jacked in: the City is the physical half, so jack out first. */
    case JackedIn = 'jacked_in';

    /** Already travelling or exploring; one thing at a time. */
    case Busy = 'busy';

    /** Travel to the district you are already in. */
    case AlreadyThere = 'already_there';

    /** No chain of links reaches it. */
    case NoRoute = 'no_route';

    /** The pod fare is not in the wallet. */
    case CannotAfford = 'cannot_afford';

    /** Nothing to cancel. */
    case NothingToCancel = 'nothing_to_cancel';

    /** Not in that district, or the place is not one you know. The same answer either way. */
    case NotHere = 'not_here';

    /** The shop does not sell that. */
    case NotSold = 'not_sold';

    /** The equipped deck has no room for it. */
    case NoStorage = 'no_storage';

    /** The clinic does not do that: not its regions, not its tiers, or no surgery at all. */
    case NotOffered = 'not_offered';

    /** Not an implant, or not in the state the operation needs. */
    case CannotOperate = 'cannot_operate';

    /** The region of the body is full. */
    case NoRoom = 'no_room';

    /** The shelf holds fewer than were asked for. */
    case SoldOut = 'sold_out';

    /** Not something they take, or not yours to sell: installed, in a container, fitted, running, or the deck you carry. */
    case CannotSell = 'cannot_sell';

    /** Nothing to mend or restore. */
    case NothingToTreat = 'nothing_to_treat';

    public function message(): string
    {
        return Text::get("city.refused.{$this->value}");
    }
}
