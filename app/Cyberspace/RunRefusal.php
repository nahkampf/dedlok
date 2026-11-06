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
 * cost a run or it is a lookup. See docs/CYBERSPACE.md, *The operating system is a
 * gate you cannot see*.
 */
enum RunRefusal: string
{
    case NotJackedIn = 'not_jacked_in';
    case NothingThere = 'nothing_there';
    case NotIntrudable = 'not_intrudable';

    /** A gateway takes Infiltrate and nothing else, and nothing else takes Infiltrate. */
    case WrongPurpose = 'wrong_purpose';

    /** The deck stopped meeting its own requirements. Checked again here, cheaply. */
    case DeckNotReady = 'deck_not_ready';

    /** Already inside one. The lock should have caught this; it is the belt. */
    /**
     * The machine is shut. It found out about somebody — possibly you, an hour
     * ago — and when the window closes it will be somewhere else.
     *
     * THIS ONE IS ALLOWED TO SAY WHAT IT IS, unlike the operating-system gate:
     * a lockdown is visible from outside (the map draws a machine you have
     * seen rebooting) and it is a consequence of something a player did rather
     * than a fact about the machine they are supposed to pay a scan for.
     */
    case LockedDown = 'locked_down';

    case AlreadyRunning = 'already_running';

    /** A hunter is on you. Cyberspace combat owns the screen until it is settled. */
    case Hunted = 'hunted';

    /** In a fight, which is settled before anything else begins. */
    case Fighting = 'fighting';

    case ProgramRefused = 'program_refused';

    /** The owner has called a party and nobody who accepted is able to go in yet: a party of one is not what was called. */
    case NobodyReady = 'nobody_ready';

    public function message(): string
    {
        return Text::get("cyberspace.run.refused.{$this->value}");
    }
}
