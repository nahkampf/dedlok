<?php

declare(strict_types=1);

namespace App\Inventory;

use App\Support\Text;

/**
 * Why an item did not go where it was asked to go.
 *
 * Same shape and the same reasoning as InjectionRefusal: every case here is an
 * ordinary thing a player can try, not an exceptional one.
 */
enum PlacementRefusal: string
{
    /** Not this character's row — either the item or the container. */
    case NotOwned = 'not_owned';

    /** The target holds nothing. Most items hold nothing. */
    case NotAContainer = 'not_a_container';

    /** A memory card takes data, not a rifle. */
    case WrongKind = 'wrong_kind';

    /** It would not fit in what is left. */
    case NoRoom = 'no_room';

    /**
     * Containers do not go inside containers.
     *
     * A deliberate depth limit rather than a cycle check: one level is all the
     * fiction needs, and a tree that cannot be deeper than one cannot contain
     * a loop for anything to recurse through.
     */
    case ContainersDoNotNest = 'containers_do_not_nest';

    /** The lang key describing this to a player. */
    /** The equipped deck has no room for it — see App\Decks\Storage. */
    case NoStorage = 'no_storage';

    public function message(): string
    {
        return Text::get("inventory.placement.{$this->value}");
    }
}
