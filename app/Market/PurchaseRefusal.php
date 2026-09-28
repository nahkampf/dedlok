<?php

declare(strict_types=1);

namespace App\Market;

use App\Support\Text;

/**
 * Why nothing was bought.
 *
 * An enum rather than an exception, like every other refusal here: reaching
 * for the last one a moment after somebody else did is an ordinary afternoon,
 * not an exceptional condition.
 */
enum PurchaseRefusal: string
{
    /** Not on the shelf — never stocked, sold out, or withdrawn from sale. */
    case NotStocked = 'not_stocked';

    /** Fewer left than were asked for. Says so rather than quietly selling what is there. */
    case NotEnoughLeft = 'not_enough_left';

    /** The money is not there. */
    case CannotAfford = 'cannot_afford';

    /** The equipped deck has no room for it — see App\Decks\Storage. */
    case NoStorage = 'no_storage';

    public function message(): string
    {
        return Text::get("market.refused.{$this->value}");
    }
}
