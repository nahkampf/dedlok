<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Supported locales
|--------------------------------------------------------------------------
|
| The single source of truth for which languages the game speaks. Adding a
| locale is: add an entry here, then copy lang/en to lang/<code>.
|
| Keys are Laravel locale codes (they name the lang/ directory). 'icu' is the
| full ICU locale handed to intl for dates, numbers and plural rules — these
| differ, and using the Laravel code where ICU is expected silently falls back
| to root locale formatting.
|
*/

return [

    'locales' => [
        'en' => [
            'name' => 'English',
            'native' => 'English',
            'icu' => 'en_GB',
            'dir' => 'ltr',
        ],
        // Adding a language is these four lines plus a lang/<code> directory.
        // Kept here as a worked example — uncomment once lang/sv exists and
        // `php artisan i18n:check` passes.
        //
        // 'sv' => [
        //     'name'   => 'Swedish',   // English name, for admin screens
        //     'native' => 'Svenska',   // what the switcher shows the player
        //     'icu'    => 'sv_SE',     // full ICU locale handed to intl
        //     'dir'    => 'ltr',       // 'rtl' for Arabic, Hebrew, Farsi
        // ],
    ],

    /*
    | Where a request's locale may come from, in priority order. The first
    | source that yields a supported locale wins. Removing 'browser' makes the
    | game always open in the fallback locale until the player chooses.
    */
    'resolution_order' => ['user', 'session', 'browser'],

    'session_key' => 'locale',

];
