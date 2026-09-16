<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Support\Text;

/**
 * Why a run did not begin.
 *
 * NOTHING HERE IS ABOUT THE OPERATING SYSTEM, and that is deliberate rather
 * than an omission. A machine whose OS the runner lacks is ENTERED, not
 * refused — every action against it then fails, indistinguishably from bad luck
 * — because a refusal naming the OS would hand over a scan fact for one click,
 * for free, with no instrument running and no minutes spent. The gate has to
 * cost a run or it is a lookup. See CYBERSPACE.md, *The operating system is a
 * gate you cannot see*.
 */
enum RunRefusal: string
{
    case NotJackedIn = 'not_jacked_in';
    case NothingThere = 'nothing_there';
    case NotIntrudable = 'not_intrudable';

    /** The deck stopped meeting its own requirements. Checked again here, cheaply. */
    case DeckNotReady = 'deck_not_ready';

    /** Already inside one. The lock should have caught this; it is the belt. */
    case AlreadyRunning = 'already_running';

    public function message(): string
    {
        return Text::get("cyberspace.run.refused.{$this->value}");
    }
}
