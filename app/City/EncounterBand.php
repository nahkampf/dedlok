<?php

declare(strict_types=1);

namespace App\City;

use App\Support\Text;
use Illuminate\Support\Facades\Config;

/**
 * A chance said as a word. The button shows the word and keeps the figure in its
 * title: a number nobody has measured reads as a promise, and the band is honest
 * about how coarse the knowledge is. The ceilings live in `config/city.php`.
 */
final class EncounterBand
{
    /** A weight as how rare it makes the encounter, for the editor's slider. */
    public static function forWeight(int $weight): string
    {
        foreach ((array) Config::get('city.encounter.weight_bands', []) as $band) {
            if ($weight <= (int) $band['upto']) {
                return Text::get("admin.encounters.weight_band.{$band['word']}");
            }
        }

        return Text::get('admin.encounters.weight_band.common');
    }

    public static function for(float $chance): string
    {
        foreach ((array) Config::get('city.encounter.bands', []) as $band) {
            if ($chance <= (float) $band['upto']) {
                return Text::get("city.encounter.band.{$band['word']}");
            }
        }

        return Text::get('city.encounter.band.safe');
    }
}
