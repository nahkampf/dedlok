<?php

declare(strict_types=1);

// Keyed by the slug in config/archetypes.php.

return [
    'deckjockey' => [
        'name' => 'Deck Jockey',
        'role' => 'Hacking',
        'description' => 'You go where the data is and take it. Ice is a puzzle until it is a coffin.',
    ],
    'crim' => [
        'name' => 'Crim',
        'role' => 'Combat',
        'description' => 'Raised by the streets, you learned to hustle young. You take what you can get away with, and fight when you have to.',
    ],
    'fixer' => [
        'name' => 'Fixer',
        'role' => 'Contacts',
        'description' => 'You know a guy. You always know a guy. The city runs on favours and you hold the ledger.',
    ],
    'hacktivist' => [
        'name' => 'Hacktivist',
        'role' => 'Data',
        'description' => 'Information wants to be free. You\'re the one to sift through stolen data dumps to extract and decrypt the secrets of the Powers That Be.',
    ],
    'sleuth' => [
        'name' => 'Sleuth',
        'role' => '???',
        'description' => 'tbd',
    ],
    'codemonkey' => [
        'name' => 'Code Monkey',
        'role' => 'Code',
        'description' => 'You\'ve done your time as a corporate cubicle rat, mass producing software on the production line. Now you make your buck by writing cutting edge custom software.',
    ],
    'bloodhound' => [
        'name' => 'Bloodhound',
        'role' => 'Combat/Scanning',
        'description' => 'Whether a clients wants someone found, a trail followed or a system scoped out, you\'re the one to get the job done.',
    ],

    // Perk labels, keyed by the perk key in config/archetypes.php. Attribute
    // perks reuse character.names.* so a rename happens in one place.
    'perks' => [
        'grey_matter' => 'Grey matter',
        'scar_tissue' => 'Scar tissue',
        'damage_taken' => 'Damage taken',
    ],
];
