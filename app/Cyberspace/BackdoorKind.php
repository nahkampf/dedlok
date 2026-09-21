<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Support\Text;

/**
 * What a backdoor is FOR, decided when it goes in and never afterwards.
 *
 * A system siphons or it routes. It cannot do both, and changing your mind
 * means going back and doing the run again — which is what makes the choice a
 * decision rather than a setting.
 *
 * THE ASYMMETRY IS THE DESIGN. A siphon is safe and slow: it pays a little
 * every night and the only thing that takes it is the ageing roll. A routing
 * hop is free and expendable: it costs nothing to hold, it does nothing on its
 * own, and a completed trace takes every one in that run's chain at once. So
 * income is insulated from routing risk — a trace that catches you never
 * touches your siphons — and the two halves of a portfolio are worth building
 * separately.
 *
 * Both give the same discount on getting back in: the approach roll is skipped
 * on a machine you already hold. Neither does anything for the core roll —
 * extracting through a backdoor is still stealing.
 */
enum BackdoorKind: string
{
    /** A little cash every night, paid straight into creds. Wants a system with something worth taking. */
    case Siphon = 'siphon';

    /** A hop in the chain a trace has to walk. Worth holding on a machine worth nothing at all. */
    case Routing = 'routing';

    public function label(): string
    {
        return Text::get("cyberspace.backdoor.kind.{$this->value}");
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
