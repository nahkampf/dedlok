<?php

declare(strict_types=1);

namespace App\Drugs;

use App\Support\Text;

/**
 * Why a dose was not taken.
 *
 * An enum rather than an exception, for the reason every other refusal in the
 * game is one: none of these is exceptional. Reaching for something that is
 * not a drug, or for somebody else's, is an ordinary afternoon.
 *
 * NOTHING ABOUT OVERDOSING IS IN THIS LIST. Taking one too many is allowed —
 * it is the mechanic, not a mistake to be protected from — and the cost lands
 * as injury rather than as a refusal.
 */
enum DoseRefusal: string
{
    /** The item has no `drugs` row, so there is nothing to take. */
    case NotADrug = 'not_a_drug';

    /** Somebody else's, or nothing left of it. */
    case NotOwned = 'not_owned';

    /** A fight-only drug, taken from the stash or anywhere else but a fight. */
    case OnlyInAFight = 'only_in_a_fight';

    public function message(): string
    {
        return Text::get("drugs.refused.{$this->value}");
    }
}
