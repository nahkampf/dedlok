<?php

declare(strict_types=1);

namespace App\Missions;

use App\Support\Text;

/**
 * Where a job is in its life.
 *
 * FIVE, AND THE LAST THREE ARE ENDINGS. A mission is offered, taken, and then
 * finishes one of three ways — which is the same shape `RunEnding` takes, and
 * for the same reason: what separates the endings is what they COST, not what
 * happened.
 *
 * `Failed` and `Voided` look alike from outside and are the whole point of the
 * distinction. Failing is the clock running out on somebody who took the work;
 * voiding is the job's subject ceasing to exist, which was never theirs to
 * answer for. One costs standing and the other costs nothing at all.
 */
enum MissionState: string
{
    /** On the board, not taken. Cleared by the next refresh whether or not anybody looked. */
    case Offered = 'offered';

    /** Taken, and the clock is running. The one state a character may only hold one of per entity. */
    case Accepted = 'accepted';

    case Completed = 'completed';

    /** The window closed with work outstanding, or it was abandoned — which costs the same on purpose. */
    case Failed = 'failed';

    /** A target stopped existing. Nobody's fault, and priced accordingly. */
    case Voided = 'voided';

    public function isOver(): bool
    {
        return $this !== self::Offered && $this !== self::Accepted;
    }

    public function label(): string
    {
        return Text::get("missions.states.{$this->value}");
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
