<?php

declare(strict_types=1);

/*
| CODE CRAFTING (CRAFTING.md): components and a Design, over time, into a program.
*/
return [

    /*
    | The skill every craft needs at least one level of, and the skill each KIND of program needs on top of it. A kind with no
    | entry here cannot be crafted at all, and the design editor refuses a product of one. Levels are the RESOLVED
    | `skill.<slug>`, so a skill modifier counts.
    */
    'general_skill' => 'code_crafting',
    'general_level' => 1,

    'gate' => [
        'icebreaker' => 'icebreaker_crafting',
        'analyser' => 'analyser_crafting',
        'decryptor' => 'decryptor_crafting',
        'script' => 'script_crafting',
    ],
    'gate_level' => 1,

    // At most this many lines in one recipe, and the rows the design editor draws.
    'max_components' => 8,
];
