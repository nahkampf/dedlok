<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | What is worth unlocking
    |--------------------------------------------------------------------------
    |
    | One entry per achievement: the metric it watches and the value that wins
    | it. The call sites never name an achievement — they report what happened
    | and this table decides whether that crossed a line, so a new achievement
    | over an existing metric is one row here and no PHP at all.
    |
    | THE KEY IS THE ID, AND IT IS PERMANENT: `user_achievements.achievement_id`
    | stores it and `public/images/achievements/<id>.jpg` is found by it, so
    | renumbering one would hand somebody else's picture to everybody who has
    | it. Retire an achievement by deleting the row and never reusing the
    | number. Names are placeholders in `strings.achievements.name` until they
    | are written, which is where they belong either way.
    |
    | A threshold is read against the RUNNING TOTAL, so an achievement added
    | later is won by a player who already passed it the next time the metric
    | moves at all.
    |
    | `secret` decides whether it is listed before it is won. An open one is on
    | the page from the first day as something to go and do; a secret one is
    | not there at all until it is unlocked, and then it is. It changes nothing
    | about how one is EARNED — `Achievements::listableFor()` is the only thing
    | that reads it, so a secret achievement unlocks exactly as loudly as any
    | other. Absent means false: a new entry is visible unless it says not.
    |
    */

    'unlocks' => [

        // A million creds out of other people's systems.
        1 => ['metric' => 'raid_creds', 'threshold' => 1_000_000, 'secret' => false],

        // Ten thousand drained quietly, a night at a time.
        2 => ['metric' => 'siphon_creds', 'threshold' => 10_000, 'secret' => false],

        /*
         * A routing chain at its maximum length. Ten is what the two routing
         * skills add up to — `routing_hops` has no ceiling of its own, so the
         * number lives here rather than being read off a stat that would
         * answer differently for every character.
         */
        3 => ['metric' => 'routing_chain', 'threshold' => 10, 'secret' => false],

        // A hundred systems taken out of existence.
        4 => ['metric' => 'systems_burned', 'threshold' => 100, 'secret' => false],

        // A hundred pieces of ICE cut down to nothing.
        5 => ['metric' => 'ice_defeated', 'threshold' => 100, 'secret' => false],

        /*
         * Flatlined once, and flatlined a hundred times. The pair is the
         * shape most of these want: the same metric read at the moment it
         * first happens and again at the point it has become a career.
         */
        6 => ['metric' => 'flatlines', 'threshold' => 1, 'secret' => false],
        7 => ['metric' => 'flatlines', 'threshold' => 100, 'secret' => false],

        // Twenty skills at the level cap, and fifty.
        8 => ['metric' => 'skills_at_max_level', 'threshold' => 20, 'secret' => false],
        9 => ['metric' => 'skills_at_max_level', 'threshold' => 50, 'secret' => false],

        // A hundred systems read all the way through, and a thousand.
        10 => ['metric' => 'systems_scanned', 'threshold' => 100, 'secret' => false],
        11 => ['metric' => 'systems_scanned', 'threshold' => 1_000, 'secret' => false],

        // A thousand positions of ground explored, and ten thousand.
        12 => ['metric' => 'grid_explored', 'threshold' => 1_000, 'secret' => false],
        13 => ['metric' => 'grid_explored', 'threshold' => 10_000, 'secret' => false],

    ],

];
