<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Support\Text;

/**
 * What a turn can be spent on.
 *
 * EVERY ONE OF THEM COSTS THE TURN, which is the economy the whole run rests
 * on: a bigger haul is more turns, more turns is more heat and more trace, and
 * nothing in a run is free. That is why this is an enum and not a set of
 * routes — one door, and a turn always costs the same thing whatever it was
 * spent doing.
 *
 * Leaving is on the list and always will be. A run with no exit is a character
 * deleted by a door they could not see.
 */
enum RunAction: string
{
    /** Cut at the ICE with whatever icebreaker is running. */
    case Attack = 'attack';

    /**
     * Take what the machine is holding.
     *
     * TOUCHES THE CORE, the same as Attack — it is the second thing CYBERSPACE
     * .md names for the IDS's second chance, alongside burning, backdooring
     * and injecting. Available on an `RunPurpose::Extraction` run; on any
     * other purpose it wastes the turn the same silent way a cut with no
     * icebreaker does, rather than being refused for free.
     */
    case Extract = 'extract';

    /**
     * Put a way back in.
     *
     * TOUCHES THE CORE, exactly as Extract does, and takes the same second
     * look from the IDS. Available on a `RunPurpose::Backdoor` run; on any
     * other purpose it wastes the turn the same silent way, because the
     * failure lines never say which gate stopped you.
     *
     * A SUCCESSFUL INSTALL IS THE ONLY THING IN CYBERSPACE THAT CLEANS UP
     * AFTER ITSELF: it erases the logs and resets what it tripped, so the
     * machine does not lock down however loud the run was. A failed one cleans
     * up nothing.
     */
    case Install = 'install';

    /**
     * Destroy the machine.
     *
     * TOUCHES THE CORE, and CYBERSPACE.md names it first in the list that does
     * — "burning, extracting, backdooring, injecting". Available on a
     * `RunPurpose::Burn` run and silently wasted on any other, the same rule
     * the other two purposed actions follow.
     *
     * IT IS THE LAST TURN OF THE RUN when it lands. There is no machine left
     * to strike back, advance a trace or be locked down, so the run ends as
     * `RunEnding::Completed` rather than leaving somebody standing in an
     * address that answers nothing. Nothing comes out of a burned machine:
     * no loot, no raid counted, and the position it held is simply empty.
     */
    case Burn = 'burn';

    /**
     * Do nothing, and let the turn pass.
     *
     * Not a courtesy — it is how a player spends a turn waiting for something,
     * and it costs exactly what any other turn costs. A run where waiting were
     * free would be a run with no clock.
     */
    /**
     * Hang a Leecher on this system's core, feeding one of your Data Fortresses. Spent by installing; any purpose.
     */
    case Leech = 'leech';

    case Hold = 'hold';

    /** The turn of an Infiltrate run: one very hard stealth check towards the gateway's watch being slipped. */
    case Infiltrate = 'infiltrate';

    /** The turn of a Denial run: the deck's bandwidth is thrown at the node, building its pressure. */
    case Flood = 'flood';

    /** Leave. Always possible, never certain. */
    case Egress = 'egress';

    public function label(): string
    {
        return Text::get("cyberspace.run.action.{$this->value}");
    }

    /**
     * Does this reach the machine?
     *
     * The distinction the operating-system gate turns on: what touches the
     * SYSTEM fails when the runner cannot operate it, and what happens to the
     * runner carries on regardless. Leaving is never blocked, and holding
     * reaches nothing.
     */
    public function touchesTheSystem(): bool
    {
        return $this === self::Attack || $this === self::Extract;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
