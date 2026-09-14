<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Support\Text;

/**
 * What kind of machine a system is.
 *
 * Type is what the map DRAWS. What a type is worth breaking into — what it
 * holds, what it runs, how hard it hits back — is phase 4, and it lands on the
 * same rows rather than on a second table, because it is more about the same
 * machine.
 *
 * The portal is a case here rather than a boolean beside it. It is the one
 * system that is none of the things a system is — it cannot be scanned,
 * intruded, burned or owned — and a `is_portal` column would be a second
 * question about the same fact, free to disagree with this one.
 */
enum SystemType: string
{
    /** Where every player arrives on jacking in. There is exactly one. */
    case Portal = 'portal';

    /** Holds data. */
    case Silo = 'silo';

    /** Sells its cycles. */
    case Compute = 'compute';

    /** Carries traffic, and one day the way into a subnet. */
    case Relay = 'relay';

    /**
     * The types a generated world is built from.
     *
     * The portal is not one of them: there is exactly one, it sits where the
     * config says, and it is placed rather than rolled.
     *
     * @return list<self>
     */
    public static function ordinary(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $type): bool => $type !== self::Portal,
        ));
    }

    /**
     * May anything at all be done to this system?
     *
     * One question rather than four — scanned, intruded, burned, owned —
     * because the portal answers no to all of them and nothing else answers no
     * to any. When something answers no to only some, this becomes four.
     */
    public function isInteractable(): bool
    {
        return $this !== self::Portal;
    }

    public function label(): string
    {
        return Text::get("cyberspace.types.{$this->value}");
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
