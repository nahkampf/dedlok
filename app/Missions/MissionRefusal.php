<?php

declare(strict_types=1);

namespace App\Missions;

use App\Support\Text;

/**
 * Why a job was not taken, handed in or given up.
 *
 * One enum for every door, the shape `OfferRefusal` takes: the same handful of
 * things go wrong at each of them and a player reading two words does not care
 * which method said no.
 */
enum MissionRefusal: string
{
    /** Not their job. The HTTP layer 404s first; this is the half that does not trust it. */
    case NotYours = 'not_yours';

    /** Taken, finished or cleared off the board by a refresh between the screen and the press. */
    case Gone = 'gone';

    /**
     * They already have work from this agent.
     *
     * One active mission per ENTITY, not per character: three desks can have
     * you working at once, and one agent cannot hand you two jobs.
     */
    case AlreadyWorking = 'already_working';

    /**
     * Their standing is under the desk's line.
     *
     * The same gate the shelf reads — an agent who will not sell to somebody
     * will not employ them either.
     */
    case NotWelcome = 'not_welcome';

    /** Handing in with something still outstanding. The screen says which, so this does not have to. */
    case NotDone = 'not_done';

    /** Nowhere on the deck to put what the desk hands over. */
    case NoRoom = 'no_room';

    /** The window closed. Said rather than silently failing, because the row is still on screen. */
    case Expired = 'expired';

    /** Not standing on the desk's node on the grid right now. */
    case NotAtDesk = 'not_at_desk';

    public function message(): string
    {
        return Text::get("missions.refused.{$this->value}");
    }
}
