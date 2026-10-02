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

    public function message(): string
    {
        return Text::get("city.refused.{$this->value}");
    }
}
