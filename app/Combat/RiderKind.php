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

    /** Lasts the whole fight and does not age. Its AMOUNT is its level: each level costs hit points every turn it is on. Engine-only. */
    case Bleeding = 'bleeding';

    /** Knocked senseless by a takedown: loses its next turn. Engine-only. */
    case Dazed = 'dazed';

    /** Lost its footing in a failed takedown: loses its next turn. Engine-only. */
    case OffBalance = 'off_balance';

    /** The same for the edge a takedown gives its taker: doubles the chance of their next turn's attack. Engine-only. */
    case Edge = 'edge';

    public function label(): string
    {
        return Text::get("combat.riders.kinds.{$this->value}");
    }

    /** Does damage each turn it lasts, resisted like a hit of its type, and so carries an amount. Authorable kinds only. */
    public function damaging(): bool
    {
        return $this->damageType() !== null;
    }

    /** The type of damage each tick deals; null for a kind that does none (or, for bleeding, none that is resisted). */
    public function damageType(): ?DamageOrigin
    {
        return match ($this) {
            self::Burning => DamageOrigin::Thermal,
            self::Poisoned => DamageOrigin::Chemical,
            default => null,
        };
    }

    /** Takes the afflicted side's action away at the start of its turn. */
    public function skipsTurn(): bool
    {
        return in_array($this, [self::Stun, self::Dazed, self::OffBalance], true);
    }

    /** Stays on for the whole fight: never counts down. */
    public function persistent(): bool
    {
        return $this === self::Bleeding;
    }

    /** What an editor may put on a weapon or a round. The rest are the engine's. */
    public function authorable(): bool
    {
        return in_array($this, [self::Stun, self::Burning, self::Poisoned], true);
    }

    /** @return list<self> */
    public static function authorableKinds(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $kind): bool => $kind->authorable()));
    }
}
