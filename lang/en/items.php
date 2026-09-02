<?php

declare(strict_types=1);

/*
| Game content lives in lang files, not the database. The items table holds
| only slug + stats; every player-visible string is keyed by slug here:
|
|     __("items.{$item->slug}.name")
|
| Adding an item means a row (stats) and an entry here (words).
*/

return [
    'sandevistan_mk5' => [
        'name' => 'Sandevistan MK.V',
        'tier' => 'Anomalous',
        'flavor' => 'Pulled from a Militech cadaver. Still warm when they found it.',
    ],
    'kiroshi_optics' => [
        'name' => 'Kiroshi Optics',
        'tier' => 'Prototype',
        'flavor' => 'Sees more than you want it to.',
    ],
    'ballistic_coprocessor' => [
        'name' => 'Ballistic Coprocessor',
        'tier' => 'Milspec',
        'flavor' => 'Does the maths so your hands do not have to.',
    ],
    'scrap_alloy' => [
        'name' => 'Scrap Alloy',
        'tier' => 'Standard',
        'flavor' => 'Worth almost nothing. Almost.',
    ],
];
