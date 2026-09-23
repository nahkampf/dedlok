<?php

declare(strict_types=1);

namespace App\Standing;

use App\Support\Text;
use Illuminate\Support\Facades\Config;

/**
 * What a standing figure MEANS, in one word.
 *
 * A card shows the band and an entity's own screen shows the number, for the
 * reason every other ratio in this game is drawn rather than printed: -12.480
 * is a fact nobody can act on, and *hated* is.
 *
 * The boundaries live in `config/standing.php` as the FLOOR of each band, so
 * retuning the vocabulary is a config change and the cases here are the
 * vocabulary itself. Nothing mechanical reads a band: the store and the
 * missions read `exchanges.min_standing`, because a gate belongs to the desk
 * and not to the adjective.
 */
enum Band: string
{
    case Hostile = 'hostile';
    case Hated = 'hated';
    case Cold = 'cold';
    case Neutral = 'neutral';
    case Known = 'known';
    case Trusted = 'trusted';
    case Allied = 'allied';

    /** The band a figure falls in. Highest floor at or below it wins. */
    public static function for(float $standing): self
    {
        $found = self::Neutral;
        $floor = null;

        /** @var list<array{at: float, band: string}> $bands */
        $bands = (array) Config::get('standing.bands', []);

        // Compared rather than sorted: the config may be authored in any
        // order, and it is a LIST because an array keyed by a float floor
        // would have those keys cast to int.
        foreach ($bands as $band) {
            $at = (float) $band['at'];

            if ($standing >= $at && ($floor === null || $at > $floor)) {
                $found = self::tryFrom((string) $band['band']) ?? $found;
                $floor = $at;
            }
        }

        return $found;
    }

    public function label(): string
    {
        return Text::get("entities.bands.{$this->value}");
    }

    /**
     * What colour it reads in. Ember is spent only on the two that mean
     * somebody is actively hunting for a reason to act on it.
     */
    public function inkToken(): string
    {
        return match ($this) {
            self::Hostile => 'var(--crit)',
            self::Hated => 'var(--warn)',
            self::Cold => 'var(--ink-2)',
            self::Neutral => 'var(--ink-1)',
            self::Known, self::Trusted, self::Allied => 'var(--ok)',
        };
    }
}
