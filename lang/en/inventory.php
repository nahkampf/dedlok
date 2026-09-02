<?php

declare(strict_types=1);

// The two inventories, and what can be done from them.

return [

    'title' => 'Stash',

    // The two containers. Named for what they are, not for the column value:
    // a player has never heard the word "container".
    'physical' => 'Carried',
    'physical_hint' => 'What is on you. Gear, chrome, salvage.',
    'data' => 'Deck',
    'data_hint' => 'What is on your deck. Software, files, skillbooks.',

    'empty' => 'Nothing here.',

    // English has two plural forms; other locales have more, and they belong
    // in that locale's file rather than at the call site.
    'count' => '{0}empty|{1}:count item|[2,*]:count items',
    'quantity' => '×:count',

    'inject' => 'Inject',
    'inject_confirm' => 'Injecting :skill destroys the book. It cannot be undone.',
    'injected' => ':skill is on your sheet. Queue it to start training.',

    'teaches' => 'Teaches :skill',

    // What is inside a container, and why something would not go in.
    'contents' => 'On this',
    'contents_free' => ':free of :capacity free',
    'store' => 'Store',
    'store_in' => 'Store on :container',
    'eject' => 'Take out',
    'stored' => ':item is on :container.',
    'ejected' => ':item is loose again.',

    'placement' => [
        'not_owned' => 'You are not carrying that.',
        'not_a_container' => 'That does not hold anything.',
        'wrong_kind' => 'That will not go on there.',
        'no_room' => 'There is no room left on it.',
        'containers_do_not_nest' => 'That already holds things. It cannot go inside something else.',
    ],

    'refused' => [
        'not_a_skillbook' => 'That is not something you can inject.',
        'not_owned' => 'You are not carrying that.',
        'already_known' => 'You already know that skill. The book is no use to you.',
        'skill_missing' => 'That book teaches a skill that no longer exists.',
    ],

];
