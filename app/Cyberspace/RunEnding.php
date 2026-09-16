<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Support\Text;

/**
 * How a run finished.
 *
 * Every one of these goes through `RunStarter::end()`, and every bad one goes
 * through `Navigator::jackOut()` after it — the order CYBERSPACE.md fixes for
 * all of them: damage, then the consequence, then the ejection, then the
 * waiting screen.
 */
enum RunEnding: string
{
    /** They left. Rolled, never certain, and never taken away from them. */
    case Egress = 'egress';

    /** The ICE won. What that costs is whatever the ICE's tags carry. */
    case Defeat = 'defeat';

    /** The deck cooked a module and threw them through the wall. */
    case Burnout = 'burnout';

    /**
     * The machine stopped existing under them.
     *
     * Burned by somebody else, or locked down. NOBODY LOST: it costs the flat
     * point of Synaptic Integrity a readiness loss costs and nothing else, and
     * what was already taken stays taken.
     */
    case Cancelled = 'cancelled';

    /** They did what they came to do. */
    case Completed = 'completed';

    public function message(): string
    {
        return Text::get("cyberspace.run.ended.{$this->value}");
    }

    /** Did this end badly enough to need the bad-ending order? */
    public function isBad(): bool
    {
        return $this === self::Defeat || $this === self::Burnout;
    }
}
