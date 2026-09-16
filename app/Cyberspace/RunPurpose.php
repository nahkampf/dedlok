<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Support\Text;

/**
 * Why somebody went in. Chosen before the run and never changed.
 *
 * MAPPING IS NOT HERE, and its absence is the point: it used to be a purpose —
 * scan only, never touch the core — and the probe took that job the day it
 * shipped. A read happens on the open grid with no run, no lock and no core to
 * avoid, and it produces the same sellable file. Two ways to do one thing is one
 * of them being wrong.
 *
 * What a purpose decides is what the turns are FOR and what the ending settles.
 * It never decides whether the machine notices you — everything hostile rolls
 * the same way whatever you came to do, which is what keeps a quiet purpose from
 * being a safe one.
 */
enum RunPurpose: string
{
    /**
     * Take what it is holding.
     *
     * Clean, it leaves no lockdown behind and the node restocks; caught, it
     * locks down like anything else. The only purpose that increments the raid
     * counter, because it is the only one that leaves a gap in the books.
     */
    case Extraction = 'extraction';

    /**
     * Keep access.
     *
     * Never locks the system down, even when the run went loudly — the install
     * erases the logs and resets what it tripped. The one operation in
     * cyberspace that cleans up after itself, and the one that does not move the
     * raid counter, because the records it would have counted are gone.
     */
    case Backdoor = 'backdoor';

    /** Destroy it. Dead, then empty, then eventually something new. */
    case Burn = 'burn';

    public function label(): string
    {
        return Text::get("cyberspace.run.purpose.{$this->value}");
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
