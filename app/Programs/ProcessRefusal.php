<?php

declare(strict_types=1);

namespace App\Programs;

use App\Support\Text;

/**
 * Why a program did not start, or did not stop.
 *
 * Same shape and the same reasoning as FittingRefusal, PlacementRefusal and
 * InjectionRefusal: every case here is an ordinary thing a player can try with
 * the screen in front of them, not an exceptional one worth an exception.
 */
enum ProcessRefusal: string
{
    /** The program, the deck, or both belong to somebody else. */
    case NotOwned = 'not_owned';

    /** The thing being started is not software. */
    case NotAProgram = 'not_a_program';

    /** It is already running. Stopping and starting are not the same button. */
    case AlreadyRunning = 'already_running';

    /**
     * As many instances of this program as this character can run are already
     * going.
     *
     * Two different walls wear this one message, because to a player they are
     * the same sentence: software that cannot multitask at all is capped at
     * one for ever, and software that can is capped at the `program_threads`
     * stat. Which wall was hit is not a thing anybody can act on differently
     * — what they do next is stop one or train for another thread.
     */
    case NoFreeThread = 'no_free_thread';

    /** It was not running, so there was nothing to stop. */
    case NotRunning = 'not_running';

    /**
     * Nothing is equipped, so there is nowhere to run it.
     *
     * A program runs ON a deck, and the deck it runs on is the one being
     * carried — which is why unequipping stops everything.
     */
    case NoDeckEquipped = 'no_deck_equipped';

    /**
     * The deck cannot run at all — see DeckTotals::isReady().
     *
     * The same gate that stands in front of jacking in. A machine missing its
     * NIC does not get to run a decryption either.
     */
    case DeckNotReady = 'deck_not_ready';

    /** Not enough free compute for what this one wants. */
    case NotEnoughCompute = 'not_enough_compute';

    /** Not enough free memory for what this one wants. */
    case NotEnoughMemory = 'not_enough_memory';

    /**
     * This kind of program is meaningless outside a run.
     *
     * An icebreaker running while nobody is in cyberspace is a deck spending
     * compute on nothing — worse than useless, because it is compute the run
     * the player actually wanted will not have. The run starts these, not the
     * player.
     */
    case RunOnly = 'run_only';

    case OneIcebreaker = 'one_icebreaker';

    /** A Locator and a Pathfinder are one slot between them. */
    case OneLocator = 'one_locator';

    /** One program that puts Jump buttons beside addresses at a time. */
    case OneJumper = 'one_jumper';

    /** Listed on the market: the offer promises the buyer it is untouched, so it cannot be run meanwhile. */
    case Listed = 'listed';

    /** A Leecher is installed on a system from a run, never run on a deck. */
    case NotRunnable = 'not_runnable';

    public function message(): string
    {
        return Text::get("programs.refused.{$this->value}");
    }
}
