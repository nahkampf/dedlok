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
