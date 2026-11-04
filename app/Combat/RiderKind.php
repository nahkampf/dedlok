<?php

declare(strict_types=1);

namespace App\Combat;

use App\Stats\DamageOrigin;
use App\Support\Text;

/**
 * A rider is something a hit can leave behind that lasts more than the hit. docs/COMBAT.md.
 *
 * Fixed vocabulary; a new kind is a case, a string and whatever the fight engine does with it.
 * The kind fixes the DAMAGE TYPE of a damage-over-time rider, so an editor never picks one:
 * burning is thermal and poison is chemical, and each ends up resisted like any other hit of
 * that type. A stun does no damage at all; it takes turns away.
 */
enum RiderKind: string
{
    case Stun = 'stun';
    case Burning = 'burning';
    case Poisoned = 'poisoned';

    public function label(): string
    {
        return Text::get("combat.riders.kinds.{$this->value}");
    }

    /** Does damage each turn it lasts, and so carries an amount. A stun carries none. */
    public function damaging(): bool
    {
        return $this !== self::Stun;
    }

    /** The type of damage each tick deals; null for a stun. */
    public function damageType(): ?DamageOrigin
    {
        return match ($this) {
            self::Stun => null,
            self::Burning => DamageOrigin::Thermal,
            self::Poisoned => DamageOrigin::Chemical,
        };
    }
}
