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

    public function label(): string
    {
        return Text::get("city.encounter.starts.{$this->value}");
    }
}
