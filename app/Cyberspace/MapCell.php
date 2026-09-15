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
        /**
         * The grade, IF this character has scanned it out of the machine.
         *
         * Null is the ordinary state and means *not found out*. A grade is
         * what a scan exists to discover, so the map may show one that was
         * discovered and may never show one that was not — which is the rule
         * in CYBERSPACE.md, kept by the only thing that could break it having
         * nowhere to get an unscanned grade from.
         */
        public ?int $grade = null,
        /**
         * Do they hold a backdoor on this machine?
         *
         * Nothing sets this yet — backdoors are phase 6 — and it is here now
         * so that the day they exist is a query and not a design argument.
         */
        public bool $hasBackdoor = false,
        /**
         * Is it locked down and coming back up?
         *
         * The other half of the same sentence: lockdown is phase 6, the cell
         * knows how to draw it already.
         */
        public bool $isRebooting = false,
        /**
         * Have they read anything out of this machine?
         *
         * A live scan file with at least one answer in it — pointing an
         * instrument at something is not the same as having learned something,
         * so a read that has come to nothing yet marks nothing. Like the grade
         * it is keyed by ADDRESS, which is what makes it clear itself: a
         * machine that relocates comes back wearing a new one and the file
         * stops describing it.
         */
        public bool $isScanned = false,
        /**
         * Is it inside what they can see from where they are standing?
         *
         * The map draws memory and the sweep lights a square of it; this is
         * which square. False for everything when they are jacked out, because
         * somebody outside cyberspace is looking at the whole thing from
         * memory and none of it is lit.
         */
        public bool $inView = false,
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
     * The class the kit paints it with, plus the newness mark.
     *
     * Composed here rather than in the template because it is a decision about
     * state, and a Blade file full of @if is a Blade file where the states stop
     * being enumerable.
     *
     * Newness is a SEPARATE class rather than another case in the match below,
     * because it is orthogonal: a new machine is still a system cell, drawn the
     * way every system cell is, with one extra thing said about it.
     */
    public function classes(): string
    {
        return implode(' ', array_filter([
            $this->modifier(),
            $this->kind(),
            $this->inView ? null : 'cell--distant',
            $this->isNew ? 'cell--new' : null,
            $this->hasBackdoor ? 'cell--backdoored' : null,
            $this->isScanned ? 'cell--scanned' : null,
            $this->isRebooting ? 'cell--rebooting' : null,
        ]));
    }

    /**
     * What KIND of machine, which is a different question from what state the
     * cell is in.
     *
     * Its own class rather than a case in the match below, for the reason
     * newness is: a compute node that is rebooting, backdoored and new is all
     * four of those things at once, and a single class per cell could only say
     * one of them.
     */
    public function kind(): ?string
    {
        $type = $this->contact?->type;

        return match ($type) {
            SystemType::Silo, SystemType::Compute, SystemType::Relay => "cell--{$type->value}",
            default => null,
        };
    }

    /**
     * The character drawn in the cell.
     *
     * A glyph per type, so a map read at a glance says WHAT is out there and
     * not merely where. Every one of these is from the set this codebase has
     * already proved against the kit's font stack — see Item::glyph() and the
     * rail — because a character that falls back to notdef is a box with a
     * question mark in it on somebody else's machine.
     */
    public function glyph(): string
    {
        if ($this->isYou) {
            return "\u{25CF}";           // ● — the one thing the eye finds first
        }

        return match ($this->contact?->type) {
            SystemType::Portal => "\u{25CD}",    // ◍ an aperture
            SystemType::Silo => "\u{25A4}",      // ▤ stacked bands: stored data
            SystemType::Compute => "\u{232C}",   // ⌬ a part that thinks
            SystemType::Relay => "\u{2630}",     // ☰ channels
            default => $this->contact !== null ? "\u{25C6}" : '',
        };
    }

    /**
     * The grade as a 0-1 ratio, for the kit's `--g`.
     *
     * A magnitude drawn as a magnitude — a bar at the foot of the cell rather
     * than a colour, because nine steps is more than colour can carry and a
     * map of four hundred traffic lights is not a map anybody reads.
     */
    public function gradeRatio(): ?float
    {
        if ($this->grade === null) {
            return null;
        }

        return round(max(0, min(Grade::MAX, $this->grade)) / Grade::MAX, 3);
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
