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

    // Rarity names, shared by every item. The tier is a number on the row; this
    // is the single place it becomes a word, so a rename lands everywhere at
    // once instead of once per item.
    'tiers' => [
        1 => 'Standard',
        2 => 'Tuned',
        3 => 'Milspec',
        4 => 'Prototype',
        5 => 'Anomalous',
    ],

    /*
    | Skillbooks are named from the skill they teach rather than one entry
    | each. There is a book per skill, so an entry each would mean adding a
    | skill also means editing every locale — and skills keep their title in
    | the database, the documented i18n exception, so the name composes.
    */
    'skillbook' => [
        'name' => 'Skillbook: :skill',
        'flavor' => 'Compressed instruction set. One read, then it is gone.',
    ],

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
