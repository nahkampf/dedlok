<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Character archetypes
|--------------------------------------------------------------------------
|
| characters.archetype stores the integer id. Everything else about an
| archetype lives here, strings included — there is no archetypes table for
| them to live on, and since translation was removed there is nowhere else
| for them to go.
|
| Ids are permanent — they are written into character rows. Retire an archetype
| by removing it from this list, never by renumbering.
|
| Perks are stored as a signed number plus a unit, not as a display string, so
| they stay usable by game logic and format per locale. 'percent' renders as
| -10%, 'flat' as +2. The label comes from archetypes.perks.<key>.
|
| PLACEHOLDER CONTENT: the six roles and their perks exist so the creation
| screen has something real to render. Balance them freely.
|
*/

return [

    'list' => [

        /*
         * A LIVE perk carries a 'modifiers' array: it produces App\Stats\Modifier
         * objects the Resolver acts on, and its card text is generated from them,
         * so display cannot drift from behaviour.
         */
        1 => [
            'slug' => 'deckjockey',
            'name' => 'Deck Jockey',
            'role' => 'Hacking',
            'description' => 'You go where the data is and take it. Ice is a puzzle until it is a coffin.',
            'perks' => [
                [
                    'key' => 'grey_matter',
                    'modifiers' => [
                        [
                            'op' => 'percentage_of_base',
                            'amount' => 5,
                            'target' => ['id' => 'synaptic_integrity_max'],
                        ],
                    ],
                ],
            ],
        ],

        2 => [
            'slug' => 'crim',
            'name' => 'Crim',
            'role' => 'Combat',
            'description' => 'Raised by the streets, you learned to hustle young. You take what you can get away with, and fight when you have to.',
            'perks' => [
                [
                    'key' => 'scar_tissue',
                    'modifiers' => [
                        [
                            'op' => 'percentage_of_base',
                            'amount' => 5,
                            'target' => ['id' => 'hitpoints_max'],
                        ],
                    ],
                ],

                /*
                 * THE EXAMPLE of the older display-only shape, kept on purpose.
                 *
                 * A perk carrying a 'modifier'/'unit' pair instead of 'modifiers'
                 * renders on the card and CHANGES NOTHING. It is retained so the
                 * shape stays documented and covered by tests while perks are
                 * still being written; delete it once every perk is live.
                 *
                 * Migrating one is a matter of replacing the pair with a
                 * 'modifiers' array. Nothing else changes.
                 */
                ['key' => 'damage_taken', 'modifier' => -15, 'unit' => 'percent'],
            ],
        ],

        // The remaining archetypes have no perks yet. Add them as LIVE ones.
        3 => [
            'slug' => 'sleuth',
            'name' => 'Sleuth',
            'role' => '???',
            'description' => 'tbd',
            'perks' => [],
        ],
        4 => [
            'slug' => 'codemonkey',
            'name' => 'Code Monkey',
            'role' => 'Code',
            'description' => 'You\'ve done your time as a corporate cubicle rat, mass producing software on the production line. Now you make your buck by writing cutting edge custom software.',
            'perks' => [],
        ],
        5 => [
            'slug' => 'fixer',
            'name' => 'Fixer',
            'role' => 'Contacts',
            'description' => 'You know a guy. You always know a guy. The city runs on favours and you hold the ledger.',
            'perks' => [],
        ],
        6 => [
            'slug' => 'hacktivist',
            'name' => 'Hacktivist',
            'role' => 'Data',
            'description' => 'Information wants to be free. You\'re the one to sift through stolen data dumps to extract and decrypt the secrets of the Powers That Be.',
            'perks' => [],
        ],
        7 => [
            'slug' => 'bloodhound',
            'name' => 'Bloodhound',
            'role' => 'Combat/Scanning',
            'description' => 'Whether a clients wants someone found, a trail followed or a system scoped out, you\'re the one to get the job done.',
            'perks' => [],
        ],

    ],

    /*
    | Perk labels, keyed by the perk key above. Attribute perks reuse the
    | attribute's own name from config/strings.php instead, so renaming
    | "Reflex" is still a one-line change.
    */
    'perk_labels' => [
        'grey_matter' => 'Grey matter',
        'scar_tissue' => 'Scar tissue',
        'damage_taken' => 'Damage taken',
    ],

];
