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

    /** An arrival on the grid by a move (a step, a jump or a bookmark; not the jack-in itself). Cyberspace's own door. */
    case GridStep = 'grid_step';

    /** Arriving at a system on the grid, by whatever move. Filters: its type, a grade range, its owner. */
    case System = 'system';

    /** Between turns of a run. Filters: a grade range, the run's purpose. Every ending says what becomes of the run. */
    case Run = 'run';

    /** The realm is the door's: these three are cyberspace and every other is the City. */
    public function isGrid(): bool
    {
        return in_array($this, [self::GridStep, self::System, self::Run], true);
    }

    public function label(): string
    {
        return Text::get("city.encounter.starts.{$this->value}");
    }
}
