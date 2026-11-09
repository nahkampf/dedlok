<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Where a new character starts
    |--------------------------------------------------------------------------
    |
    | The slug of the district a body begins in. Read by the Character model's
    | creating hook, so the factory, character creation and anything else that
    | makes a character agree on one answer. See docs/CITY.md.
    |
    */
    'start' => 'old-town',

    /*
    | The shop `city:stock-skills` stocks every night with each skill that is for sale, at list price. A location
    | slug. A shop that does not exist yet is simply skipped.
    */
    'skills_shop' => 'skillsrus',

    /*
    |--------------------------------------------------------------------------
    | Selling to a place
    |--------------------------------------------------------------------------
    |
    | A buy listing (a location's or an exchange's) pays its `percent` of the item's stock price, and may cap how
    | many it takes per window. `reset_hours` is that window: the market's own eight-hour cycle, and the one
    | place it is set. The window settles lazily under the buyer's lock.
    |
    */
    'sell' => [
        'reset_hours' => 8,
    ],

    /*
    |--------------------------------------------------------------------------
    | Implant surgery
    |--------------------------------------------------------------------------
    |
    | A timed City activity at a clinic that offers it. `seconds` is per tier of the
    | implant, resolved through `surgery_duration`. FITTING is a check: the clinic's
    | rating against `difficulty` for the implant's tier, with its own chance quoted
    | beforehand. A failure costs `injury_per_tier` points of injury a tier — neural
    | damage for the head and spine, physical trauma elsewhere — and the implant is
    | rejected (it stays in the stash). Taking one out is a fee and time and no roll.
    | Unbalanced.
    |
    */
    'surgery' => [
        'seconds' => ['install' => 300, 'remove' => 120],
        'difficulty' => [1 => 20, 2 => 35],
        'injury_per_tier' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Travel, in seconds
    |--------------------------------------------------------------------------
    |
    | A travel is one pass in every district on the route except the
    | destination. A pass takes `seconds` times the district's `travel_weight`,
    | resolved through `travel_duration`. A pod takes `pod_time` of that, applied
    | AFTER the modifiers, and costs `pod_fare` per district on the route, the
    | origin and the destination both counting. Unbalanced.
    |
    */
    'travel' => [
        'seconds' => 30,
        'pod_time' => 0.5,
        'pod_fare' => 25,
    ],

    /*
    |--------------------------------------------------------------------------
    | Exploring
    |--------------------------------------------------------------------------
    |
    | One pass, `seconds` long, resolved through `explore_duration`. It rolls once
    | per still-hidden explorable location in the district, against the
    | difficulty for its concealment, with the resolved `exploration` stat.
    |
    | The difficulties are chosen so a starting character (a pool near 14) finds
    | a Trivial place about half the time, a Difficult one a fifth, an Obscure
    | one a twentieth and a Secretive one a hundredth. THEY ARE ONLY TRUE AT
    | `checks.spread` 8: retuning the spread moves all four.
    |
    */
    'explore' => [
        'seconds' => 240,
        'difficulty' => [
            'trivial' => 14,
            'difficult' => 25,
            'obscure' => 38,
            'secretive' => 51,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Encounters
    |--------------------------------------------------------------------------
    |
    | `travel_chance` and `explore_chance` are the odds that a matured pass opens
    | one at all. They are the BASE of the resolved `encounter_chance` stat, so a
    | skill that quiets the street is a modifier. Neither is measured.
    |
    | `pool` is what makes RARE mean rare. An encounter's `weight` is its share of
    | max(the eligible weights added up, this), so a lone encounter of weight 2 is
    | a 2% pick, not a certainty because nothing else qualified. The rest of the
    | pool is "nothing happens".
    |
    | `bands` name how likely a rolled choice is, lowest first: the first row whose
    | ceiling the chance does not exceed.
    |
    */
    'encounter' => [
        'travel_chance' => 0.11,
        'explore_chance' => 0.40,
        // Arriving at a place has no door-wide chance: only an encounter with its own chance can arrive.
        'arrival_chance' => 0.0,
        'pool' => 100,
        // How rare an encounter's weight reads in the editor, lowest first: the first row whose
        // ceiling the weight does not exceed. The weight is its share of the pool, 1 to 100.
        'weight_bands' => [
            ['upto' => 2, 'word' => 'ultra_rare'],
            ['upto' => 8, 'word' => 'rare'],
            ['upto' => 25, 'word' => 'uncommon'],
            ['upto' => 100, 'word' => 'common'],
        ],
        'bands' => [
            ['upto' => 0.10, 'word' => 'long_shot'],
            ['upto' => 0.30, 'word' => 'unlikely'],
            ['upto' => 0.45, 'word' => 'risky'],
            ['upto' => 0.60, 'word' => 'even'],
            ['upto' => 0.80, 'word' => 'likely'],
            ['upto' => 1.00, 'word' => 'safe'],
        ],
    ],
];
