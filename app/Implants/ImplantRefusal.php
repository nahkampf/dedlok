<?php

declare(strict_types=1);

namespace App\Implants;

use App\Support\Text;

/**
 * Why an implant did not go in, or did not come out.
 *
 * Same shape and reasoning as FittingRefusal and TravelRefusal: every case is
 * an ordinary thing a player can try with the screen in front of them.
 */
enum ImplantRefusal: string
{
    /** Somebody else's. */
    case NotOwned = 'not_owned';

    /** Not an implant at all. A cyberdeck does not go in your chest. */
    case NotAnImplant = 'not_an_implant';

    /** Already in. */
    case AlreadyInstalled = 'already_installed';

    /** Not in, so there is nothing to take out. */
    case NotInstalled = 'not_installed';

    /** That region is full. Take something out of it first. */
    case NoRoom = 'no_room';

    /**
     * It is inside a container, or fitted into a deck.
     *
     * Refused rather than quietly pulled out: taking something out of your bag
     * is a thing you did, and a screen that does it for you is a screen that
     * moved your possessions without saying so. The same rule ModuleFitter
     * follows for a stowed module.
     */
    case NotToHand = 'not_to_hand';

    public function message(): string
    {
        return Text::get("implants.refused.{$this->value}");
    }
}
