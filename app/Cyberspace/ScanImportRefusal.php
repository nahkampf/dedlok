<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Support\Text;

/**
 * Why a file did not become knowledge.
 *
 * NOTHING HERE SPENDS THE FILE. Every case leaves the row where it was, which
 * is the difference between this and every other door that consumes something:
 * a refusal that ate the copy would be the worst bug this class could have.
 */
enum ScanImportRefusal: string
{
    /** The row is not a scan file at all. A hand-built request, or a card that went stale in a tab. */
    case NotAFile = 'not_a_file';

    /**
     * Past its 48 hours.
     *
     * The clock travelled with the file, which is what stops a stockpile being
     * a warehouse — and what makes a file bought at hour 47 a bad buy rather
     * than an impossible one.
     */
    case Stale = 'stale';

    /**
     * The address no longer answers.
     *
     * Burned, or relocated since the read. The file still DESCRIBES something
     * truthfully; there is simply nothing left for it to describe, and writing
     * a scan of a machine that is not there would put a grade on the map
     * against whatever moves in.
     */
    case Gone = 'gone';

    /**
     * They already have the whole picture, and it is still good.
     *
     * A partial file of the same machine is replaced rather than refused —
     * that is a file being worth something. Only a COMPLETE live one has
     * nothing left to learn from a copy.
     */
    case AlreadyKnown = 'already_known';

    public function message(): string
    {
        return Text::get("cyberspace.reading.import_{$this->value}");
    }
}
