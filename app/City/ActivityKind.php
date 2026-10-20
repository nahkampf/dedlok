<?php

declare(strict_types=1);

namespace App\City;

/** What a character is doing in the City. Stored on `city_activities.kind`. */
enum ActivityKind: string
{
    case Travel = 'travel';
    case Explore = 'explore';
    case Surgery = 'surgery';
    case Rest = 'rest';
    case Workout = 'workout';
    case Study = 'study';
    case Simstim = 'simstim';
    case Stakeout = 'stakeout';
    case ScopeOut = 'scope_out';

    /** Rest and the safehouse sessions: open-ended, stopped by getting up, settled by `HomeActions`. */
    public function isSession(): bool
    {
        return in_array($this, [self::Rest, self::Workout, self::Study, self::Simstim], true);
    }
}
