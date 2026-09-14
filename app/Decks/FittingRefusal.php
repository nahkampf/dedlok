<?php

declare(strict_types=1);

namespace App\Decks;

use App\Support\Text;

/**
 * Why a module did not go into a socket, or did not come out of one.
 *
 * Same shape and the same reasoning as PlacementRefusal and InjectionRefusal:
 * every case here is an ordinary thing a player can try with the screen in
 * front of them, not an exceptional one worth an exception.
 */
enum FittingRefusal: string
{
    /** The deck, the module, or both belong to somebody else. */
    case NotOwned = 'not_owned';

    /** The thing being fitted into is not a cyberdeck. */
    case NotADeck = 'not_a_deck';

    /** The thing being fitted is not a module. A deck is not one either. */
    case NotAModule = 'not_a_module';

    /**
     * That socket is on a different deck.
     *
     * Sockets belong to the definition, so a Kurogane's CPU socket is a real
     * row that has nothing to do with the Hosaka in front of you.
     */
    case WrongDeck = 'wrong_deck';

    /** A Socket K does not take a Socket Z chip, and no socket takes a PSU. */
    case WrongSocket = 'wrong_socket';

    /** Something is already in it. Take that out first. */
    case SocketOccupied = 'socket_occupied';

    /** The module is already in a deck — this one or another. */
    case AlreadyFitted = 'already_fitted';

    /** Nothing to take out. */
    case NotFitted = 'not_fitted';

    /** Unequipping when nothing is equipped. */
    case NotEquipped = 'not_equipped';

    /**
     * The equipped deck presents no socket of that kind at all.
     *
     * Different from every socket being full, and a player needs the two told
     * apart: one is a deck that will never take this, the other is a deck that
     * will once something comes out.
     */
    case DeckHasNoSocket = 'deck_has_no_socket';

    /** Every socket that would take it is occupied. */
    case NoFreeSocket = 'no_free_socket';

    /**
     * The module is inside a container.
     *
     * Refused rather than quietly ejected: taking something out of your bag is
     * a thing you did, and a screen that does it for you is a screen that
     * moved your possessions without saying so.
     */
    case Stowed = 'stowed';

    /**
     * The deck is running and you are inside it.
     *
     * Covers fitting, taking out, stripping, unequipping and destroying — the
     * whole of what the deck lock refuses. Jack out first.
     */
    case JackedIn = 'jacked_in';

    public function message(): string
    {
        return Text::get("decks.fitting.{$this->value}");
    }
}
