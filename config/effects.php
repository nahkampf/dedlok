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

    /*
    |--------------------------------------------------------------------------
    | Fatigue: Tired, Drained, Exhausted
    |--------------------------------------------------------------------------
    |
    | What a body does when it is spent (FATIGUE.md). `App\Characters\Fatigue` is the only thing that writes these rows:
    | Tired at 75% of the maximum, Drained at 90% (and it REPLACES Tired, one row at a time), Exhausted from a collapse
    | until fatigue is under 50%. `config/fatigue.php` lists the three keys, and `fatigue_max` is resolved WITHOUT them so
    | a lower attribute cannot lower the ceiling it is judged against.
    */
    'fatigue_tired' => [
        'label' => 'Tired',
        'stacking' => 'unique',
        'retired' => false,
        'modifiers' => [
            ['op' => 'add', 'amount' => -3, 'target' => ['id' => 'focus']],
            ['op' => 'add', 'amount' => -3, 'target' => ['id' => 'intellect']],
            ['op' => 'add', 'amount' => -3, 'target' => ['id' => 'reflex']],
        ],
    ],

    'fatigue_drained' => [
        'label' => 'Drained',
        'stacking' => 'unique',
        'retired' => false,
        'modifiers' => [
            ['op' => 'add', 'amount' => -7, 'target' => ['id' => 'focus']],
            ['op' => 'add', 'amount' => -5, 'target' => ['id' => 'intellect']],
            ['op' => 'add', 'amount' => -5, 'target' => ['id' => 'reflex']],
        ],
    ],

    /* Mood bands (`config/mood.php`). Small by design. */
    'mood_despondent' => [
        'label' => 'Despondent',
        'stacking' => 'unique',
        'retired' => false,
        'modifiers' => [
            ['op' => 'add', 'amount' => -4, 'target' => ['id' => 'focus']],
            ['op' => 'add', 'amount' => -4, 'target' => ['id' => 'social']],
        ],
    ],

    'mood_low' => [
        'label' => 'Low',
        'stacking' => 'unique',
        'retired' => false,
        'modifiers' => [
            ['op' => 'add', 'amount' => -2, 'target' => ['id' => 'focus']],
            ['op' => 'add', 'amount' => -2, 'target' => ['id' => 'social']],
        ],
    ],

    'mood_good' => [
        'label' => 'Good mood',
        'stacking' => 'unique',
        'retired' => false,
        'modifiers' => [
            ['op' => 'add', 'amount' => 1, 'target' => ['id' => 'focus']],
            ['op' => 'add', 'amount' => 1, 'target' => ['id' => 'social']],
        ],
    ],

    'mood_elated' => [
        'label' => 'Elated',
        'stacking' => 'unique',
        'retired' => false,
        'modifiers' => [
            ['op' => 'add', 'amount' => 2, 'target' => ['id' => 'focus']],
            ['op' => 'add', 'amount' => 2, 'target' => ['id' => 'social']],
        ],
    ],

    'fatigue_exhausted' => [
        'label' => 'Exhausted',
        'stacking' => 'unique',
        'retired' => false,
        'modifiers' => [
            ['op' => 'add', 'amount' => -10, 'target' => ['id' => 'intellect']],
            ['op' => 'add', 'amount' => -10, 'target' => ['id' => 'memory']],
            ['op' => 'add', 'amount' => -10, 'target' => ['id' => 'perception']],
            ['op' => 'add', 'amount' => -10, 'target' => ['id' => 'reflex']],
            ['op' => 'add', 'amount' => -10, 'target' => ['id' => 'focus']],
            ['op' => 'add', 'amount' => -10, 'target' => ['id' => 'social']],
            ['op' => 'add', 'amount' => -10, 'target' => ['id' => 'meat']],
            ['op' => 'add', 'amount' => -10, 'target' => ['id' => 'motor']],
            ['op' => 'add', 'amount' => -10, 'target' => ['id' => 'senses']],
            ['op' => 'add', 'amount' => -10, 'target' => ['id' => 'muscle']],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Withdrawal, one definition per drug family
    |--------------------------------------------------------------------------
    |
    | WANTING SOMETHING IS A CAUSE WITH NO ROW OF ITS OWN, which is exactly
    | what this file is for. A character past the addiction threshold for a
    | family wears its definition until they take something of that family
    | again — App\Drugs\DrugLedger is the only thing that writes or clears one.
    |
    | ONE PER FAMILY, because there is nothing generic to say. Coming off a
    | stimulant is not coming off an opiate, and a single "withdrawal" entry
    | would be a label doing the work a mechanic should.
    |
    | THEY ARE FLAT AND THEY ARE BLUNT. Each takes points off the attributes
    | the family was being used to prop up, so the shape of the trap is legible
    | from the numbers: the thing you took to be sharper leaves you duller than
    | you started. `unique`, since a person is either in withdrawal or is not —
    | there is no second row to add up.
    |
    | Retiring one means `retired => true` and never a deletion: rows in the
    | database name these keys.
    |
    */

    'withdrawal_stimulant' => [
        'label' => 'Coming down',
        'stacking' => 'unique',
        'modifiers' => [
            ['op' => 'add', 'amount' => -4, 'target' => ['id' => 'focus']],
            ['op' => 'add', 'amount' => -2, 'target' => ['id' => 'reflex']],
        ],
    ],

    'withdrawal_opiate' => [
        'label' => 'Hurting',
        'stacking' => 'unique',
        'modifiers' => [
            ['op' => 'add', 'amount' => -4, 'target' => ['id' => 'focus']],
            ['op' => 'add', 'amount' => -3, 'target' => ['id' => 'meat']],
        ],
    ],

    'withdrawal_sedative' => [
        'label' => 'Wired',
        'stacking' => 'unique',
        'modifiers' => [
            ['op' => 'add', 'amount' => -3, 'target' => ['id' => 'motor']],
            ['op' => 'add', 'amount' => -3, 'target' => ['id' => 'focus']],
        ],
    ],

    // Nothing in the catalogue hooks anybody yet (Rewire is not addictive),
    // but every family answers for its own withdrawal.
    'withdrawal_nootropic' => [
        'label' => 'Foggy',
        'stacking' => 'unique',
        'modifiers' => [
            ['op' => 'add', 'amount' => -2, 'target' => ['id' => 'memory']],
            ['op' => 'add', 'amount' => -2, 'target' => ['id' => 'focus']],
        ],
    ],

    'withdrawal_psychedelic' => [
        'label' => 'Flat',
        'stacking' => 'unique',
        'modifiers' => [
            ['op' => 'add', 'amount' => -3, 'target' => ['id' => 'perception']],
            ['op' => 'add', 'amount' => -2, 'target' => ['id' => 'intellect']],
        ],
    ],

];
