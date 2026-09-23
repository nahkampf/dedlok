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
 *
 * THE EXCHANGE IS WHY THERE ARE FOUR QUESTIONS NOW AND NOT ONE. This file
 * used to ask `isInteractable()` and say so in its own words: *one question
 * rather than four, because the portal answers no to all of them and nothing
 * else answers no to any — when something answers no to only some, this
 * becomes four.* An Exchange answers no to being scanned, run against and
 * burned, and YES to being owned: it is somebody's front desk, and who runs
 * it is the whole point of standing on it.
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
     * An entity's front desk: missions and a loyalty store, and nothing a
     * runner can do TO it. Placed rather than rolled, like the portal, and
     * permanent — an Exchange is never raided, shut or moved, which is what
     * lets somebody walk back to one they found last week.
     */
    case Exchange = 'exchange';

    /**
     * The types a generated world is built from.
     *
     * Neither the portal nor an Exchange is one of them: both are PLACED
     * rather than rolled — one because there is exactly one of it, the other
     * because an entity's desks are authored and sit where their grade says.
     *
     * @return list<self>
     */
    public static function ordinary(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $type): bool => ! in_array($type, [self::Portal, self::Exchange], true),
        ));
    }

    /**
     * May a run be started against this system?
     *
     * The one that used to be `isInteractable()`, kept under its old name
     * because it is what four callers meant by it: the door a run knocks on.
     * No for the portal, no for an Exchange — you do business at a desk, you
     * do not break into it.
     */
    public function isInteractable(): bool
    {
        return ! in_array($this, [self::Portal, self::Exchange], true);
    }

    /** Can a probe read anything out of it? An Exchange has nothing to find. */
    public function isScannable(): bool
    {
        return $this->isInteractable();
    }

    /** Does it defend itself? Nothing placed rather than rolled does. */
    public function isDefended(): bool
    {
        return $this->isInteractable();
    }

    /**
     * Does somebody answer for it?
     *
     * THE ONE THAT SPLITS FROM THE OTHER THREE. An Exchange is owned — by the
     * entity whose desk it is, which is the whole reason to stand on it — and
     * the portal is owned by nobody at all.
     */
    public function isOwned(): bool
    {
        return $this !== self::Portal;
    }

    /** An entity's front desk, with an agent behind it. */
    public function isExchange(): bool
    {
        return $this === self::Exchange;
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
