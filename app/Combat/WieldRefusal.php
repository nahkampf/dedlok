<?php

declare(strict_types=1);

namespace App\Combat;

use App\Support\Text;

/** Why nothing was picked up or put down. Every case is an ordinary thing a player can try. */
enum WieldRefusal: string
{
    case NotOwned = 'not_owned';

    /** Not a weapon, or a weapon whose row has gone. */
    case NotAWeapon = 'not_a_weapon';

    /** Inside a container, fitted, running or installed: it has to be in the hands first. */
    case NotFree = 'not_free';

    case AlreadyWielded = 'already_wielded';

    case NotWielded = 'not_wielded';

    /** The class is gated by a skill (`combat.skill_gates`) the character has not trained far enough. */
    case RequiresSkill = 'requires_skill';

    /** @param  array<string, string|int>  $with  placeholders, `skill` and `level` for RequiresSkill */
    public function message(array $with = []): string
    {
        return Text::get("combat.weapons.refused.{$this->value}", $with);
    }
}
