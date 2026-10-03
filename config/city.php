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
    | makes a character agree on one answer. See CITY.md.
    |
    */
    'start' => 'old-town',

    /*
    |--------------------------------------------------------------------------
    | Selling to a place
    |--------------------------------------------------------------------------
    |
    | What a place pays when its buy listing names no price: this fraction of the
    | item's base cost. Kept well under what a shop charges so that buying and
    | reselling cannot print money. Unbalanced.
    |
    */
    'sell' => [
        'fraction' => 0.4,
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
];
