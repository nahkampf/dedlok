<?php

declare(strict_types=1);

namespace App\City;

use App\Support\Text;

/** What can open an encounter. A fourth door is a case, a label and a call to `Encounters::open()`. */
enum EncounterStart: string
{
    /** A pass of a trip matured, in the district it reached. */
    case Travel = 'travel';

    /** A pass of exploring matured, after its discovery was settled. */
    case Explore = 'explore';

    /** A button on a location's page. The door authored quests walk through. */
    case Place = 'place';

    /** Arriving at a place: rolled once per arrival, and it takes the screen. The chance-based sibling of `Place`'s button. */
    case Arrival = 'arrival';

    /** An arrival on the grid by a move (a step, a jump or a bookmark; not the jack-in itself). Cyberspace's own door. */
    case GridStep = 'grid_step';

    /** Arriving at a system on the grid, by whatever move. Filters: its type, a grade range, its owner. */
    case System = 'system';

    /** Between turns of a run. Filters: a grade range, the run's purpose. Every ending says what becomes of the run. */
    case Run = 'run';

    /** A run the player was in has ended and they are still up on the grid. Filters: purpose, system type, a grade range, its owner. */
    case AfterRun = 'after_run';

    /** A button in the grid screen's Interact box, at one authored system. Not rolled: always on offer while it may be. */
    case Spot = 'spot';

    /** The realm is the door's: these four are cyberspace and every other is the City. */
    public function isGrid(): bool
    {
        return in_array($this, [self::GridStep, self::System, self::Run, self::AfterRun, self::Spot], true);
    }

    public function label(): string
    {
        return Text::get("city.encounter.starts.{$this->value}");
    }
}
