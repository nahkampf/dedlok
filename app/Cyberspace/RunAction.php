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
     * Do nothing, and let the turn pass.
     *
     * Not a courtesy — it is how a player spends a turn waiting for something,
     * and it costs exactly what any other turn costs. A run where waiting were
     * free would be a run with no clock.
     */
    case Hold = 'hold';

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
        return $this === self::Attack;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
