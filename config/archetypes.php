<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Character archetypes
|--------------------------------------------------------------------------
|
| characters.archetype stores the integer id. Only ids, slugs and mechanical
| values live here; every player-visible string is in lang/<locale>/archetypes.php
| keyed by slug, the same arrangement as items.
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
        3 => ['slug' => 'sleuth', 'perks' => []],
        4 => ['slug' => 'codemonkey', 'perks' => []],
        5 => ['slug' => 'fixer', 'perks' => []],
        6 => ['slug' => 'hacktivist', 'perks' => []],
        7 => ['slug' => 'bloodhound', 'perks' => []],

    ],

];
