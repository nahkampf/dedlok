<?php

/*
|--------------------------------------------------------------------------
| Fatigue
|--------------------------------------------------------------------------
|
| FATIGUE.md is the design. Every number here is a placeholder until it has been played.
*/

return [

    /*
     * What an action adds to fatigue, in points. Negative eases it. Each is read by the one writer that owns the
     * action; a kind not listed costs nothing.
     */
    'costs' => [
        'grid_step' => 0.1,
        'scan' => 0.5,
        'run_turn' => 1.0,
        'fight_round' => 2.5,
        'travel_pass' => 5.0,
        'travel_pod' => -1.0,
        'explore_pass' => 7.0,
        'ask_around' => 0.5,
        'stakeout' => 1.0,
        'scope_out' => 0.5,
        'break_in' => 10.0,
        'rob' => 10.0,
        'burn' => 15.0,
    ],

    /*
     * The `config/effects.php` definitions fatigue causes. `fatigue_max` is resolved without them (`Sheet::baseFor()`),
     * so they cannot feed back into the figure they are judged against. Defined in the build's step 4.
     */
    'effects' => ['fatigue_tired', 'fatigue_drained', 'fatigue_exhausted'],

    /* Where Tired and Drained begin, as a share of the maximum. */
    'tired_at' => 0.75,
    'drained_at' => 0.90,

    /* Fullness: a hidden bar that only limits eating. Drained per minute by the clock. */
    'fullness' => [
        'max' => 50,
        'drain_per_minute' => 0.07,
    ],

    'collapse' => [
        /* Seconds on the floor. */
        'down_seconds' => 120,
        /* Where you wake, as a share of the maximum. */
        'wake_at' => 0.95,
        /* Damage to HP or SI, as a share of that resource's maximum. Fixed, not rolled. */
        'damage' => 0.20,
        /* Chance of being mugged where you fell, and the share of creds carried that goes. */
        'mugging_chance' => 20,
        'mugging_min' => 10,
        'mugging_max' => 33,
    ],

];
