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

    /** A tree run: that action belongs to another node (the core, or the entry for a Flood). Your position is on screen. */
    case NotHere = 'not_here';

    /** A tree run: a Move to a node that is not one edge from where you stand. */
    case NotANeighbour = 'not_a_neighbour';

    /** A tree run: a Move through a Tripgate the wrong way. */
    case OneWay = 'one_way';

    /** A tree run: a Loopback with no Loopback script to hand. */
    case NoLoopback = 'no_loopback';

    /** A tree run: an Egress Tunnel with no Egress Tunnel script to hand, or without the skill to start one. */
    case NoTunnel = 'no_tunnel';

    /** A tree run: the deck will not run the Egress Tunnel (memory, compute, or no deck). */
    case TunnelRefused = 'tunnel_refused';

    /** A tree run: the deck will not run the Loopback (memory, compute, or no deck). */
    case LoopbackRefused = 'loopback_refused';

    /** A tree run: a Move on past barrier ICE that is not broken. */
    case BarrierHolds = 'barrier_holds';

    /** A tree run: an Ensnare trap holds the runner, so a Move is refused. */
    case Ensnared = 'ensnared';

    /** A tree run: Nmap with nothing left in the Nmapper's reach to reveal. */
    case NothingToMap = 'nothing_to_map';

    /** A tree run: Nmap with no Nmapper running. */
    case NoNmapper = 'no_nmapper';

    /** A tree run: an Unlock with no Firewall Exploit to hand that is strong enough for this system's grade. */
    case NoKey = 'no_key';

    /** A tree run: a Move on through a firewall nobody has breached yet. */
    case FirewallClosed = 'firewall_closed';

    public function message(): string
    {
        return Text::get("cyberspace.run.refused.{$this->value}");
    }
}
