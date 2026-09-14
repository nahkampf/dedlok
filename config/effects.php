<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Effects
|--------------------------------------------------------------------------
|
| Modifiers whose cause has NO ROW OF ITS OWN — injuries, statuses, lingering
| things that simply happen to a person. A `character_modifiers` row names one
| of these by key and the resolver applies what it says.
|
| Anything that DOES have a row carries its modifiers on that row instead, as
| JSON: `archetype_perks.modifiers` and `programs.modifiers` already do, and an
| implant will, because an implant is an item and items are rows. Nothing here
| describes a thing you can hold.
|
| DEFINITION KEYS ARE PERMANENT. Rows in the database reference them, so a
| deleted key orphans live data. Retire one with `retired => true`: nothing new
| may be written against it, and what already exists keeps resolving. Never
| remove or rename a key.
|
| The shape of a definition:
|
|   'key' => [
|       'label'     => 'What the player sees in a breakdown',
|       'stacking'  => 'sum' | 'highest' | 'unique' | 'unique_by_source',
|       'retired'   => false,
|       'modifiers' => [ ...the same shape perks and programs use... ],
|   ]
|
| An amount written as the string ':amount' is filled from the instance's
| `data`, which is how ONE definition covers every depth of an injury rather
| than needing a definition per point.
|
*/

return [

    /*
    | Permanent damage to a character's MAXIMUM Synaptic Integrity.
    |
    | ONE ROW PER CHARACTER, DEEPENED RATHER THAN REPEATED. A character
    | carrying -5 who takes another -3 carries -8; the row is updated, not
    | joined by a second. The nerves do not care what caused the damage, only
    | that there is more of it — which is what lets a freeze, an ICE flatline
    | and, later, a drug overdose all write into the same injury without any of
    | them knowing the others exist, and lets a clinic heal one number.
    |
    | `unique` rather than `sum` for exactly that reason: the accumulation
    | happens in the row's own magnitude, so there is never a second row to add
    | up. See App\Stats\InjuryLedger, which is the only thing that writes it.
    |
    | Maximum SI is a derived stat and derived stats are never stored, so this
    | is a modifier rather than a subtraction into a column. The stat floors at
    | 1, so accumulated injury converges on a maximum of 1 rather than 0: it
    | permanently cripples and never itself kills.
    */
    'neural_damage' => [
        'label' => 'Neural damage',
        'stacking' => 'unique',
        'retired' => false,
        'modifiers' => [
            ['op' => 'add', 'amount' => ':amount', 'target' => ['id' => 'synaptic_integrity_max']],
        ],
    ],

    /*
    | The twin: permanent damage to maximum hit points.
    |
    | Same shape, same single-row accumulation, same floor of 1. It exists here
    | so the injury ledger has both halves from the start rather than growing a
    | second one later — nothing inflicts it yet.
    */
    'physical_trauma' => [
        'label' => 'Physical trauma',
        'stacking' => 'unique',
        'retired' => false,
        'modifiers' => [
            ['op' => 'add', 'amount' => ':amount', 'target' => ['id' => 'hitpoints_max']],
        ],
    ],

];
