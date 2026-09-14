<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Support\Text;

/**
 * Why a bookmark was not made, or not removed.
 *
 * The same shape as TravelRefusal and FittingRefusal: every case is an ordinary
 * thing a player can try with the screen in front of them, so it is a value
 * rather than an exception.
 */
enum BookmarkRefusal: string
{
    /** Already saved. Bookmarking a place twice makes one bookmark, not two. */
    case AlreadySaved = 'already_saved';

    /** That position is not on the grid at all. */
    case OffTheGrid = 'off_the_grid';

    /**
     * Ground the character has never looked at.
     *
     * THE GATE THAT PACES EXPLORATION. A position bookmarked sight unseen would
     * make coordinates tradeable, and a traded coordinate is a map somebody
     * else walked. Sweep it yourself first.
     */
    case NeverSwept = 'never_swept';

    /** Eight hexadecimal characters, and that was not eight of them. */
    case NotAnAddress = 'not_an_address';

    /** Somebody else's bookmark. */
    case NotYours = 'not_yours';

    public function message(): string
    {
        return Text::get("cyberspace.bookmarks.refused.{$this->value}");
    }
}
