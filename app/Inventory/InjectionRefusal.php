<?php

declare(strict_types=1);

namespace App\Inventory;

use App\Support\Text;

/**
 * Why an injection did not happen.
 *
 * An enum rather than an exception because every one of these is an ordinary
 * thing a player can do — clicking Inject on a book for a skill they already
 * know is not exceptional, it is Tuesday. The controller turns the case into a
 * translated message; nothing needs a stack trace.
 */
enum InjectionRefusal: string
{
    /** The item does no such thing — most items are not imprints. */
    case NotAnImprint = 'not_an_imprint';

    /** Not this character's row, or already spent. */
    case NotOwned = 'not_owned';

    /** The skill is already on the sheet. Injecting again would reset nothing. */
    case AlreadyKnown = 'already_known';

    /** The definition points at a skill that is no longer there. */
    case SkillMissing = 'skill_missing';

    /** The lang key describing this to a player. */
    public function message(): string
    {
        return Text::get("inventory.refused.{$this->value}");
    }
}
