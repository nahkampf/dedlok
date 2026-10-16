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
        'size' => ['min' => 4, 'max' => 8],
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
    | A figure may also be a table by grade. The survey's creds are one: x3 at grade 1, falling by half a point
    | a grade to x1 at grade 5, because a survey pays per JOB however many systems it names (3 to 10), which
    | made the low desks poor for the scanning they ask. Creds only: grade 1 standing is held low on purpose.
    |
    | Supply is the low-risk half of the board and priced as trade rather than
    | service: the creds are the reward and the standing deliberately is not,
    | which keeps scanning from being the quiet fastest route up the ladder.
    |
    */
    'actions' => [
        'scan' => ['creds' => [1 => 3.0, 2 => 2.5, 3 => 2.0, 4 => 1.5, 5 => 1.0], 'standing' => 1.0, 'loyalty' => 1.0],
        'burn' => ['creds' => 2.0, 'standing' => 1.5, 'loyalty' => 2.0],
        'plant' => ['creds' => 1.4, 'standing' => 2.0, 'loyalty' => 2.0],
        'agitate' => ['creds' => 1.6, 'standing' => 1.5, 'loyalty' => 1.5],
        'extract' => ['creds' => 1.8, 'standing' => 1.5, 'loyalty' => 1.5],
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
    | FROM GRADE 2 UP, EACH GRADE PAYS ABOUT 2.3x THE ONE BELOW IT, so the top
    | of the ladder is where standing is made rather than where the curve
    | flattens. A grade 5 job is worth about forty grade 1 jobs.
    |
    | GRADE 1 IS HELD LOW ON PURPOSE, because it is the work anybody can take
    | in unlimited amounts: at 0.01-0.03 it tops out near 56 even at seven
    | jobs a day — past the grade 4 desk, never the grade 5 one.
    |
    | THE PENALTY IS ABOUT ONE AVERAGE JOB. Failing and abandoning cost the
    | same, so a job taken and dropped is a job's worth given back — and a
    | high-grade job is a bet at even stakes rather than a free roll.
    |
    | THE CLIMB, against the 0.25% nightly drift in config/standing.php, from
    | nothing to the grade 5 desk working each rung in turn: about eight months
    | at three jobs a day, about three at seven. Standing settles at 400x a
    | day's earnings, so what one grade of work can reach alone depends on how
    | much of it somebody does — grade 1 tops out near 24 at three a day and
    | near 56 at seven. Above grade 1 the gates are enforced by time more than
    | by ceilings.
    |
    | CREDS WERE CUT TENFOLD on 2026-09-28, every grade alike; the ratios
    | between grades are unchanged.
    |
    | STANDING AND LOYALTY WERE DOUBLED on 2026-10-12, every grade alike, and
    | the penalties were left where they were. So the climb is about half the
    | time (the figures above are the older, slower ones: roughly three
    | months to the grade 5 desk at three jobs a day, six weeks at seven), a
    | failed job is now worth about half of what a job pays, and grade 1 work
    | alone settles near 48 at three jobs a day and near 112 at seven.
    |
    | GRADE 1 WAS THEN CUT BY 30% (2026-10-12), standing and loyalty only: its settle-out of 48 and 112 was past
    | the grade 5 desk's gate of 60, which the grade 1 band was held low to avoid. Standing is exactly 0.7x
    | (0.014 to 0.042). Loyalty is a whole number rolled in its band, so 2 to 4 became 1 to 3, a third off
    | rather than 30%. The ceilings are now about 34 at three jobs a day and about 78 at seven on scan work (a
    | mixed board is a quarter more), so seven a day of grade 1 alone still passes the grade 5 gate.
    |
    | `standing` here is the BASE handed to the resolver as `standing_gain`,
    | never the figure that lands. A skill or an implant that makes somebody
    | better at being liked is a modifier, and the mission writer never learns
    | it exists.
    |
    */
    'grades' => [
        1 => ['creds' => [40, 90], 'standing' => [0.014, 0.042], 'loyalty' => [1, 3], 'penalty' => 0.02],
        2 => ['creds' => [90, 200], 'standing' => [0.10, 0.18], 'loyalty' => [4, 8], 'penalty' => 0.07],
        3 => ['creds' => [200, 450], 'standing' => [0.22, 0.42], 'loyalty' => [8, 16], 'penalty' => 0.16],
        4 => ['creds' => [450, 900], 'standing' => [0.52, 0.96], 'loyalty' => [16, 30], 'penalty' => 0.37],
        5 => ['creds' => [900, 1800], 'standing' => [1.28, 2.12], 'loyalty' => [30, 60], 'penalty' => 0.85],
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
