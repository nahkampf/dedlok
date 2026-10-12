<?php

declare(strict_types=1);

namespace App\City;

use App\Support\Text;

/** What the ending of a run encounter does to the run it interrupted. Decided by the ending and by nothing else. */
enum EncounterRunEnd: string
{
    /** The run ends, through `RunStarter::end()` as `RunEnding::Encounter`. The character stays on the grid. */
    case KillRun = 'kill_run';

    /** The run is exactly where it was, and the player has their next action. */
    case ContinueRun = 'continue_run';

    public function label(): string
    {
        return Text::get("admin.encounters.run_end.{$this->value}");
    }
}
