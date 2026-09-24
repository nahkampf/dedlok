<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Work done for an entity
    |--------------------------------------------------------------------------
    |
    | A mission is a designated set of systems, an action required of each, and
    | a clock. Everything here is a first pass: the shape is what is being
    | committed to, and the numbers are meant to be retuned once anybody has
    | actually ground a desk.
    |
    | THE REWARDS ARE SNAPSHOTTED ONTO THE ROW at the moment a job is OFFERED,
    | so retuning this file never changes a job somebody already took. The same
    | call `market_purchases.unit_price` makes about a price.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | The board
    |--------------------------------------------------------------------------
    |
    | Rolled per character, per desk, and refreshed on the hour — lazily, when
    | the screen is opened and what is there is older than the window. Nobody
    | can take your job and two players at one desk see different work.
    |
    | THE BOARD IS NEVER EMPTY. Whatever the roll says, `floor` jobs are on it:
    | an agent with nothing to offer is a screen that wasted the walk.
    |
    */
    'board' => [
        'refresh_minutes' => 60,
        'floor' => 1,
        'size' => ['min' => 2, 'max' => 4],
    ],

    /*
    |--------------------------------------------------------------------------
    | The clock
    |--------------------------------------------------------------------------
    |
    | Rolled per mission and started on ACCEPT, never on offer: a job sitting
    | on a board is not costing anybody anything.
    |
    | IT MUST STAY UNDER `cyberspace.scan.lifetime_hours`, WHICH IS 48. A scan
    | file made in the first minute of a job has to still be good at hand-in,
    | and that is a rule rather than a happy accident — a window past the
    | file's life would make the file's expiry a second, invisible deadline.
    | MissionsConfigTest pins it.
    |
    */
    'window_hours' => ['min' => 4, 'max' => 24],

    // One active mission per ENTITY. Three desks can have you working at once.
    'active_per_entity' => 1,

    /*
    |--------------------------------------------------------------------------
    | What each action is worth, as a multiplier
    |--------------------------------------------------------------------------
    |
    | Applied to the grade's own figures below. A burn pays the most creds
    | because it is the sharp end of the board; a plant pays the most standing
    | because handing somebody a permanent foothold on a rival's machine is
    | worth more to them than intel and more dangerous to be caught at.
    |
    | Supply is the low-risk half of the board and priced as trade rather than
    | service: the creds are the reward and the standing deliberately is not,
    | which keeps scanning from being the quiet fastest route up the ladder.
    |
    */
    'actions' => [
        'scan' => ['creds' => 1.0, 'standing' => 1.0, 'loyalty' => 1.0],
        'burn' => ['creds' => 2.0, 'standing' => 1.5, 'loyalty' => 2.0],
        'plant' => ['creds' => 1.4, 'standing' => 2.0, 'loyalty' => 2.0],
        'supply' => ['creds' => 1.2, 'standing' => 0.5, 'loyalty' => 0.5],
    ],

    /*
    |--------------------------------------------------------------------------
    | Both sides scale with the agent's grade
    |--------------------------------------------------------------------------
    |
    | Creds, standing and loyalty all climb, and so does what failing costs.
    | One table per grade so the whole curve is one thing to retune.
    |
    | THE STANDING FIGURES ARE SMALL ON PURPOSE AND THE CLIMB IS LONG. At six
    | to eight jobs a day against one entity this is roughly a month to the
    | grade 2 desk and three to four months to the grade 5 desk — a browser
    | game's arc, not a weekend's. The 0.5% nightly drift is what stops it
    | being a straight line: grade 1 work alone settles around 30 and no amount
    | of it reaches 60.
    |
    | `standing` here is the BASE handed to the resolver as `standing_gain`,
    | never the figure that lands. A skill or an implant that makes somebody
    | better at being liked is a modifier, and the mission writer never learns
    | it exists.
    |
    */
    'grades' => [
        1 => ['creds' => [400, 900], 'standing' => [0.01, 0.03], 'loyalty' => [1, 2], 'penalty' => 0.02],
        2 => ['creds' => [900, 2000], 'standing' => [0.03, 0.07], 'loyalty' => [2, 4], 'penalty' => 0.05],
        3 => ['creds' => [2000, 4500], 'standing' => [0.07, 0.15], 'loyalty' => [4, 8], 'penalty' => 0.10],
        4 => ['creds' => [4500, 9000], 'standing' => [0.15, 0.30], 'loyalty' => [8, 15], 'penalty' => 0.20],
        5 => ['creds' => [9000, 18000], 'standing' => [0.30, 0.50], 'loyalty' => [15, 30], 'penalty' => 0.35],
    ],

    /*
    |--------------------------------------------------------------------------
    | Supply: how many files, by grade
    |--------------------------------------------------------------------------
    |
    | Quantity falls as the grade rises, because a grade 8 file costs a great
    | deal more to make than a grade 2 one.
    |
    | ONLY COMPLETE FILES COUNT — every readable fact known. A one-pass file
    | that learned the grade and nothing else is not intel, and requiring the
    | whole picture is what makes the instrument and the skills behind it
    | matter.
    |
    */
    'supply' => [
        1 => [6, 10],
        2 => [5, 9],
        3 => [4, 7],
        4 => [3, 5],
        5 => [2, 4],
    ],

];
