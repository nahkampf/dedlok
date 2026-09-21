<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Support\Text;

/**
 * Where a message came from.
 *
 * A CATEGORY IS NOT A SEVERITY, and this list is deliberately short for that
 * reason: it names the part of the game that raised the message, so a player
 * scanning an inbox can tell at a glance which ones are about the grid and
 * which are about the clock. Nothing reads it to decide how loud anything is
 * — a notification that needed acting on this instant would belong on the
 * screen where the acting happens, not here.
 *
 * THERE IS NO COLOUR ON THESE, on purpose. The kit's semantic aliases mean
 * good, careful, bad and worth knowing; painting Training green would say
 * something about a message that is not true of it. The word and the glyph are
 * the whole distinction.
 *
 * A CASE IS ONLY ADDED WITH A SENDER BEHIND IT. Every one of the four below
 * is written by something that runs today — the grid, the training clock, the
 * achievement tail, the nightly bounty roll — and a fifth that nothing raises
 * would be a filter with nothing to filter.
 */
enum Category: string
{
    /** The grid: scans that finished, backdoors that were found. */
    case Cyberspace = 'cyberspace';

    /** The training clock, which moves whether or not anybody is watching. */
    case Training = 'training';

    /** The account's own record, from the tail of `Achievements::check()`. */
    case Achievement = 'achievement';

    /**
     * The world outside, deciding things about you.
     *
     * Heat's first consequence is the only sender today: word going out that
     * somebody is worth money. It is the one category with nowhere to send a
     * reader yet — the street is a screen the game does not have — which is
     * what `route()` returning null is for.
     */
    case Street = 'street';

    public function label(): string
    {
        return Text::get("notifications.categories.{$this->value}");
    }

    /**
     * The rail's own glyph for the same part of the game.
     *
     * Not a new set: each of these is already in the dock, which is where the
     * question "does this render in the kit's font stack" was answered for it.
     */
    public function glyph(): string
    {
        return match ($this) {
            self::Cyberspace => "\u{25A6}",
            self::Training => "\u{2726}",
            self::Achievement => "\u{2605}",
            // The one glyph not lifted from the rail, because the street
            // has no rail item. U+26A0 is the same Miscellaneous Symbols
            // block as the rest, with U+FE0E's job done by the escape.
            self::Street => "\u{26A0}",
        };
    }

    /**
     * Where reading it takes you, or null when there is nowhere yet.
     *
     * A ROUTE NAME RATHER THAN A STORED URL. A message is about a moment and
     * outlives what it names, so a link written onto the row would rot the
     * first time a route moved — and there is nothing more specific to point
     * at anyway: cyberspace is a map rather than a page per machine.
     *
     * NULL IS AN ORDINARY ANSWER. A message about the street has nowhere to
     * lead until there is a street, and a link to somewhere unrelated would be
     * worse than no link: the row simply stops being one.
     */
    public function route(): ?string
    {
        return match ($this) {
            self::Cyberspace => 'cyberspace.index',
            self::Training => 'skills.index',
            self::Achievement => 'achievements.index',
            self::Street => null,
        };
    }
}
