<?php

declare(strict_types=1);

namespace App\Stats\Sources;

use App\Stats\Modifier;

/**
 * Anything that can contribute modifiers to a character.
 *
 * Implants, drugs, injuries, gear and archetype perks all become one of these.
 * The Resolver never learns what any of them are — it only sees Modifier
 * objects, which is the whole reason a new kind of source costs no arithmetic.
 */
interface ModifierSource
{
    /** @return list<Modifier> */
    public function modifiers(): array;
}
