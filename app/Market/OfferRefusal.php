<?php

declare(strict_types=1);

namespace App\Market;

use App\Support\Text;

/**
 * Why a listing, a withdrawal or a sale did not happen.
 *
 * One enum for all three doors, because the same handful of things go wrong at
 * each of them and a player reading two words does not care which method
 * refused.
 */
enum OfferRefusal: string
{
    /** Not their row, or not their offer. The HTTP layer 404s first; this is the half that does not trust it. */
    case NotYours = 'not_yours';

    /**
     * Spoken for.
     *
     * Inside a container, fitted into a socket, running, installed in a body,
     * or already listed — `InventoryItem::scopeLoose()` decides, so a sixth
     * way to be elsewhere never has to be learned here.
     */
    case NotToHand = 'not_to_hand';

    /**
     * The deck they are carrying.
     *
     * Not a jack-in rule and deliberately simpler than one: a deck being
     * carried is not for sale at all, so there is no window in which somebody
     * sells the machine they are standing in. Put it down first.
     */
    case Equipped = 'equipped';

    /** Sold, withdrawn, or the row destroyed under it. All the same answer: it is not there now. */
    case Gone = 'gone';

    /** Buying your own listing. Nothing would happen, which is worth saying rather than doing. */
    case YourOwn = 'your_own';

    /**
     * Asking for more of a pile than is left.
     *
     * Refused rather than quietly reduced, which is the rule the shelf already
     * keeps: somebody who asked for five and could have had two did not decide
     * that.
     */
    case NotEnoughLeft = 'not_enough_left';

    case CannotAfford = 'cannot_afford';

    public function message(): string
    {
        return Text::get("market.offers.refused.{$this->value}");
    }
}
