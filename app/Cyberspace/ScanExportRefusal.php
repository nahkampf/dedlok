<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Support\Text;

/**
 * Why a read did not become a file.
 *
 * Two cases, both of them something a player can reasonably try: the screen
 * that offered the button and the press that follows it are minutes apart,
 * and a read can go stale in between.
 */
enum ScanExportRefusal: string
{
    /**
     * Spent, stale, or never there.
     *
     * One answer for all three, like `no_file` on the reading dialog — a file
     * that has gone is a thing the screen has to be able to say, and which
     * way it went is not a distinction the player can act on.
     */
    case Gone = 'gone';

    /**
     * Something is still unknown.
     *
     * A one-pass file that learned the grade and nothing else is not intel,
     * and requiring the whole picture is what makes the instrument and the
     * skills behind it worth paying for. `SystemScan::isComplete()` decides.
     */
    case Incomplete = 'incomplete';

    /** The equipped deck has no room for it — see App\Decks\Storage. */
    case NoStorage = 'no_storage';

    public function message(): string
    {
        return Text::get("cyberspace.reading.export_{$this->value}");
    }
}
