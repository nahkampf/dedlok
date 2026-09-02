<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Skills
|--------------------------------------------------------------------------
|
| Skill DEFINITIONS live in the database, not here — see MECHANICS.md. What
| lives here is the maths: the numbers that decide how long training takes.
| They are config rather than columns because they are global rules, not
| per-skill data, and because they are expected to be tuned.
|
*/

return [

    'max_level' => 5,

    /*
    | The ranks a skill may have. Rank multiplies every threshold, so it is a
    | training-time multiplier: a rank 2 skill takes twice as long as a rank 1
    | skill to reach the same level.
    */
    'ranks' => [1, 2, 4, 8, 16],

    /*
    | Skill point thresholds.
    |
    |     multiplier(L) = round(scaling_factor * growth_multiplier ^ (L - 1))
    |     threshold(L, rank) = level_one_base * rank * multiplier(L)
    |
    | which gives, for rank 1: 242, 1210, 6776, 40898, 242726.
    |
    | These are CUMULATIVE totals — the points needed to have reached level L,
    | not the points needed to get from L-1 to L.
    */
    'thresholds' => [
        'level_one_base' => 242,
        'scaling_factor' => 0.8,
        'growth_multiplier' => 5.95,
    ],

    /*
    | Skill points per minute.
    |
    |     sp_per_minute = seconds_per_minute
    |                     * (primary_weight * primary + secondary_weight * secondary)
    |
    | Both attributes are the RESOLVED values, so an implant that raises Focus
    | speeds up training of Focus-primary skills with no special handling.
    |
    | Expected to be tuned: App\Skills\TrainingRate is the only thing that reads
    | these, so the formula can be changed in one place.
    */
    'training_rate' => [
        'primary_weight' => 0.121,
        'secondary_weight' => 0.0762,
        'seconds_per_minute' => 60,
    ],

];
