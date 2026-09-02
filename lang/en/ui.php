<?php

declare(strict_types=1);

/*
| Interface chrome. Keys describe the ROLE of the string, never its English
| text, so a copy change never forces a key rename across every locale.
*/

return [
    'nav' => [
        'run' => 'Run',
        'stash' => 'Stash',
        'contracts' => 'Contracts',
        'profile' => 'Profile',
    ],
    'action' => [
        'jack_in' => 'Jack in',
        'jack_out' => 'Jack out',
        'disconnect' => 'Disconnect',
        'cancel' => 'Cancel',
        'save' => 'Save',
    ],
    'status' => [
        'online' => 'Online',
        'nominal' => 'Nominal',
        'degrading' => 'Degrading',
        'traced' => 'Traced',
    ],
    'vitals' => [
        'health' => 'Health',
        'stamina' => 'Stamina',
        'heat' => 'Heat',
        'ram' => 'RAM',
    ],
    'locale' => [
        'label' => 'Language',
        'switched' => 'Language changed to :language.',
    ],
];
