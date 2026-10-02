<?php

declare(strict_types=1);

namespace App\City;

use App\Models\Character;
use App\Models\Location;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of `character_locations`: a character has FOUND a location.
 *
 * Only an explorable location is ever found. An open one is visible already and
 * a flagged one is visible when its flag is, so a row for either would be a
 * second answer to a question `Location::scopeVisibleTo()` already settles.
 */
final class Discovery
{
    /** @return bool true when this is news: the location was explorable and not yet found */
    public static function unlock(Character $character, Location $location): bool
    {
        if ($location->discovery !== DiscoveryRule::Explorable) {
            return false;
        }

        return DB::table('character_locations')->insertOrIgnore([
            'character_id' => $character->getKey(),
            'location_id' => $location->getKey(),
            'found_at' => now(),
        ]) === 1;
    }
}
