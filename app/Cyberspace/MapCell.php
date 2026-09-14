<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\Bookmark;
use App\Models\SystemContact;

/**
 * One square of the drawn map, and everything the screen needs to know to draw
 * it.
 *
 * Assembled once by MapView so the template asks no questions of its own — a
 * Blade file that could reach a database would be a Blade file running a query
 * per cell, four hundred times.
 *
 * NOTHING HERE IS LIVE. `contact` is what the character REMEMBERS standing
 * here, which may be a machine that has since moved or burned. The only cells
 * that are certainly current are the ones inside the view window, and they are
 * current because arriving swept them a moment ago.
 */
final readonly class MapCell
{
    public function __construct(
        public Position $position,
        /** False beyond the edge of the world: a hole in the drawing, not a place. */
        public bool $onGrid,
        /** Has this character ever looked at it? Unswept ground shows nothing at all. */
        public bool $swept,
        /** What was standing here when they last looked, or null. */
        public ?SystemContact $contact,
        /** A waypoint saved on this exact position, or null. */
        public ?Bookmark $bookmark,
        /** Are they standing here? At most one cell is. */
        public bool $isYou,
        /** One of the eight they can step to from where they are standing. */
        public bool $isStep,
        /**
         * Has the machine here only just appeared to them?
         *
         * The point of the whole feature: somebody who walks the same stretch
         * often can spot the one machine that was not there last time. False
         * for empty ground, which cannot be new — a position never changes.
         */
        public bool $isNew = false,
    ) {}

    /**
     * Is there anything to open?
     *
     * A cell with nothing to say is not interactive, which keeps the map from
     * being four hundred buttons that mostly do nothing. Steppable cells are
     * interactive whatever is on them, because stepping is the thing you do to
     * empty ground.
     */
    public function isInteractive(): bool
    {
        return $this->isStep || $this->contact !== null || $this->bookmark !== null;
    }

    /** Swept, and nothing was there. Space rather than a dead node. */
    public function isEmptyGround(): bool
    {
        return $this->swept && $this->contact === null;
    }

    /**
     * The class the kit paints it with.
     *
     * Composed here rather than in the template because it is a decision about
     * state, and a Blade file full of @if is a Blade file where the states stop
     * being enumerable.
     */
    /**
     * The class the kit paints it with, plus the newness mark.
     *
     * Newness is a SEPARATE class rather than another case in the match below,
     * because it is orthogonal: a new machine is still a system cell, drawn the
     * way every system cell is, with one extra thing said about it.
     */
    public function classes(): string
    {
        return $this->modifier().($this->isNew ? ' cell--new' : '');
    }

    public function modifier(): string
    {
        return match (true) {
            ! $this->onGrid => 'cell--void',
            ! $this->swept => 'cell--unswept',
            $this->isYou => 'cell--you',
            $this->contact?->type === SystemType::Portal => 'cell--portal',
            $this->contact !== null => 'cell--system',
            default => 'cell--empty',
        };
    }
}
