<?php

declare(strict_types=1);

namespace App\Standing;

use App\Support\Text;

/**
 * Why nothing was handed over.
 *
 * Every case is something a player can reasonably try, which is why these are
 * values rather than exceptions — the shape `PurchaseRefusal` and
 * `OfferRefusal` already take.
 */
enum LoyaltyRefusal: string
{
    /**
     * The agent will not deal with them at all.
     *
     * `exchanges.min_standing`, the same gate their work reads: a desk that
     * will not employ somebody does not sell to them either.
     */
    case NotWelcome = 'not_welcome';

    /**
     * They deal, but not in THIS.
     *
     * The second gate, per row — almost always satisfied, because
     * `entity_offers.min_standing` defaults to 0. A row with 25 in it is an
     * agent keeping something back for people they trust.
     */
    case NotForYou = 'not_for_you';

    /** Not enough loyalty. The only currency this shop takes. */
    case CannotAfford = 'cannot_afford';

    /** A service that would do nothing for them — heat removal at no heat. */
    case NothingToDo = 'nothing_to_do';

    /** Withdrawn from the shelf, or its item deleted, between the screen and the press. */
    case Gone = 'gone';

    /** The equipped deck has no room for it — see App\Decks\Storage. */
    case NoStorage = 'no_storage';

    public function message(): string
    {
        return Text::get("exchange.store_refused.{$this->value}");
    }
}
