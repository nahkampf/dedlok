<?php

declare(strict_types=1);

namespace App\City;

use App\Models\Character;
use App\Models\CityActivity;
use App\Models\Location;

/**
 * Can this body act at this place right now? THE ONE ANSWER shops and clinics
 * share, asked inside the writer's lock so a hand-built request is caught too.
 *
 * Not jacked in, nothing under way, the place is one the character can SEE
 * (a hidden place and a place that does not exist are the same refusal), and
 * the body is in its district.
 */
final class Presence
{
    public static function refusal(Character $character, Location $place): ?CityRefusal
    {
        if ($character->isJackedIn()) {
            return CityRefusal::JackedIn;
        }

        if (CityActivity::query()->whereKey($character->getKey())->exists()) {
            return CityRefusal::Busy;
        }

        $known = Location::query()->whereKey($place->getKey())->visibleTo($character)->exists();

        if (! $known || $character->district_id !== $place->district_id) {
            return CityRefusal::NotHere;
        }

        return null;
    }
}
