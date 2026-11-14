<?php

declare(strict_types=1);

namespace App\Cyberspace\Tree;

use App\Support\Text;

/** What a trap does when it goes off (docs/RUN_TREE.md). One per trap, from the seed. */
enum TrapEffect: string
{
    /** Loud noise on the bar, with no stealth roll. */
    case Alarm = 'alarm';

    /** Starts the runner's trace, or moves it on by a turn's worth (two from grade 7). */
    case Trace = 'trace';

    /** Holds the runner on the trap for a turn (two from grade 7): moves are refused. */
    case Ensnare = 'ensnare';

    /** The trap node tears itself out before the runner arrives: the way through it is gone for the run. */
    case Disconnect = 'disconnect';

    public function label(): string
    {
        return Text::get('cyberspace.tree.trap.'.$this->value);
    }
}
