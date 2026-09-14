<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Support\Text;

/**
 * Which of the two things a bookmark saves.
 *
 * They are one enum rather than two classes because a player sees one list,
 * but they are genuinely different promises: a waypoint is permanent and a
 * system bookmark is not. Anything reading a bookmark has to know which it has
 * before it can say whether "nothing there" is news.
 */
enum BookmarkKind: string
{
    /**
     * A position. Survives everything, because a position cannot move.
     *
     * Only ever set on ground the character has swept — otherwise coordinates
     * would be tradeable and exploration would be solved by whoever posted a
     * list of them.
     */
    case Waypoint = 'waypoint';

    /**
     * An address. Dies with the system wearing it.
     *
     * Set on ANY address, seen or not: trading addresses is expected, and it
     * is harmless because an address carries no position.
     */
    case System = 'system';

    /** Can this bookmark stop working on its own? */
    public function isPerishable(): bool
    {
        return $this === self::System;
    }

    public function label(): string
    {
        return Text::get("cyberspace.bookmarks.kind.{$this->value}");
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
