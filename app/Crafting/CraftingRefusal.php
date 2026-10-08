<?php

declare(strict_types=1);

namespace App\Crafting;

use App\Support\Text;

/** Why a build did not start. Every one is an ordinary thing to try; the message says what to do about it. */
enum CraftingRefusal: string
{
    /** Not yours, not in your hands, or not a design. */
    case NotADesign = 'not_a_design';

    /** The product has been deleted from the game, or is no program a craft can make. */
    case ProductGone = 'product_gone';

    /** No skill is authored for the product's kind, so nobody can make one. */
    case Uncraftable = 'uncraftable';

    case NoSkill = 'no_skill';

    case NoFreeSlot = 'no_free_slot';

    case NoDeck = 'no_deck';

    case DeckNotReady = 'deck_not_ready';

    case MissingComponents = 'missing_components';

    /** It would work, but spends components of a higher grade than the recipe needs; the player has not said yes. */
    case WastesGrade = 'wastes_grade';

    case NotYours = 'not_yours';

    public function message(): string
    {
        return Text::get("crafting.refused.{$this->value}");
    }
}
