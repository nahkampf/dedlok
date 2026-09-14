<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Support\Text;

/**
 * Why a character did not end up where they meant to.
 *
 * Same shape and the same reasoning as FittingRefusal, PlacementRefusal and
 * InjectionRefusal: every case here is an ordinary thing a player can try with
 * the screen in front of them, so a refusal is a value rather than an
 * exception.
 *
 * Jacking in, jacking out, stepping and jumping share one vocabulary because
 * they are one act — changing where you are — and one screen shows all four.
 * Splitting them would mean a caller that has to know which enum a refusal
 * came from before it can say what happened.
 */
enum TravelRefusal: string
{
    /** Nothing is equipped. You cannot go in without a deck. */
    case NoDeck = 'no_deck';

    /**
     * The equipped deck will not run.
     *
     * DeckTotals::isReady() is the whole check and there is no second list —
     * compute, memory, link, power, and draw within supply. The screen names
     * what is missing; this only says that something is.
     */
    case DeckNotReady = 'deck_not_ready';

    /** Already in cyberspace. Jacking in twice is not a thing. */
    case AlreadyJackedIn = 'already_jacked_in';

    /**
     * Not in cyberspace, and the thing asked for only happens inside it.
     *
     * Covers jacking out, stepping and jumping alike: all three are things you
     * do from somewhere, and being nowhere is not somewhere.
     */
    case NotJackedIn = 'not_jacked_in';

    /**
     * That is not one step from here.
     *
     * Movement is eight-directional and one position at a time, so this is a
     * request that did not come from the screen — a stale tab, or a hand-made
     * POST. Told apart from OffTheGrid because that one is a place a player
     * can genuinely try to walk to.
     */
    case NotAStep = 'not_a_step';

    /**
     * There is nothing that way.
     *
     * THE GRID DOES NOT WRAP, so the edge is a real wall rather than a seam,
     * and walking into it is an ordinary thing to do once. It deserves its own
     * sentence.
     */
    case OffTheGrid = 'off_the_grid';

    /** Eight hexadecimal characters, and that was not eight of them. */
    case NotAnAddress = 'not_an_address';

    /**
     * Nothing answers to that address.
     *
     * THE ORDINARY ANSWER, not a failure. Addresses die with the systems that
     * wore them, so a jump to one somebody traded you last week is allowed to
     * arrive nowhere — that is the world having moved on, and it is the
     * mechanic that keeps exploration from being a thing you finish.
     */
    case NoSuchAddress = 'no_such_address';

    /** Somebody else's bookmark. */
    case NotYours = 'not_yours';

    public function message(): string
    {
        return Text::get("cyberspace.travel.{$this->value}");
    }
}
