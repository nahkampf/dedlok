<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Support\Text;

/**
 * Why a scan did not happen.
 *
 * Every case is something a player can reasonably try, which is why these are
 * values rather than exceptions — the same shape TravelRefusal has.
 */
enum ProbeRefusal: string
{
    case NotJackedIn = 'not_jacked_in';

    /** Empty ground. A scan is of a machine, and there is no machine here. */
    case NothingThere = 'nothing_there';

    /**
     * The portal. It is the one system that is none of the things a system is
     * — it cannot be scanned, intruded, burned or owned — and
     * `SystemType::isInteractable()` is the one place that is decided.
     */
    case NotScannable = 'not_scannable';

    /**
     * No scanner running on the deck being carried.
     *
     * Reading a machine takes software, and the software has to be running —
     * owning it is not the same as having it up. Says what to do about it,
     * because there is exactly one thing.
     */
    case NoAnalyser = 'no_analyser';

    /** Everything about it is already known, and the file is still good. */
    case AlreadyKnown = 'already_known';

    /**
     * The passes are used up.
     *
     * The picture this scan has is the picture it gets. Not a failure of the
     * character — a machine can simply be beyond reading today — and the way
     * out is to come back once the file has expired.
     */
    case Exhausted = 'exhausted';

    public function message(): string
    {
        return Text::get("cyberspace.scan.{$this->value}");
    }
}
