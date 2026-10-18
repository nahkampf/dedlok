<?php

/*
|--------------------------------------------------------------------------
| Mood
|--------------------------------------------------------------------------
|
| TODO.md, *Mood*. A stored figure from -100 to +100, moved by events and drifting back to 0. PLACEHOLDERS until balance.
*/

return [

    'min' => -100,
    'max' => 100,

    /* Points a minute towards 0 (the minute clock); 0.01 is about a week from the top. */
    'drift_per_minute' => 0.01,

    /* Beyond this far from 0 either way, it drifts back at the faster rate instead. */
    'extreme_beyond' => 70,
    'extreme_drift_per_minute' => 0.03,

    /*
     * Bands, most extreme first: the figure at or beyond `at` earns the `effect` (a `config/effects.php` key) and the
     * word. One at a time; the middle band has no effect. Keys are permanent: Mood::sync() removes them by name.
     */
    'bands' => [
        ['at' => -75, 'effect' => 'mood_despondent', 'word' => 'despondent'],
        ['at' => -40, 'effect' => 'mood_low', 'word' => 'low'],
        ['at' => 75, 'effect' => 'mood_elated', 'word' => 'elated'],
        ['at' => 40, 'effect' => 'mood_good', 'word' => 'good'],
    ],

    /* What happens to them, as a signed amount. `injury` is per point of harm. */
    'events' => [
        'fight_won' => 8,
        'fight_lost' => -10,
        'collapse' => -15,
        'injury' => -1,
        'meal' => 2,
        'dose' => 2,
        'raided' => -10,
        'burned' => -30,
        // Working out a safehouse: every stakeout or scouting pass wears on them, a pass that turned something up lifts them.
        'watch_pass' => -0.4,
        'lead_success' => 0.7,
        'lead_failure' => -1,
        'break_in' => 5,
        'break_in_failed' => -5,
        'rob' => 10,
        'burn' => 2,
    ],

];
