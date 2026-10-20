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
        'leecher' => 'leecher_crafting',
        'kernel' => 'kernel_crafting',
        'fortress_ice' => 'fortress_ice_crafting',
        'fortress_ids' => 'fortress_ids_crafting',
        'booster' => 'booster_crafting',
        'fortress_decryptor' => 'fortress_decryptor_crafting',
        'fortress_assembler' => 'assembler_crafting',
        'fortress_network_tap' => 'network_tap_crafting',
        'fortress_wiretap' => 'wiretap_crafting',
        'cloak' => 'utility_crafting',
        'market' => 'utility_crafting',
        'relocation' => 'utility_crafting',
        'tunnel' => 'utility_crafting',
    ],
    'gate_level' => 1,

    // At most this many lines in one recipe, and the rows the design editor draws.
    'max_components' => 10,
];
