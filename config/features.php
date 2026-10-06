<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Feature flags
|--------------------------------------------------------------------------
|
| A flag is a switch for something not ready for everybody. Each one is read as `config('features.<name>')`, defaults
| to OFF, and is set in `.env`. What every flag does, and every place that reads it, is written down in CLAUDE.md under
| *Feature flags* — keep that list in step when a flag gains a reader.
*/
return [

    /*
    | ALPHA TESTING. While it is on, nobody can register without a valid, unused BETA KEY, and the admin has a Beta
    | keys section to make them. Off, registration is open exactly as before and the section is not there.
    |
    | Turning it off does not delete keys or forget who used which: the table is simply not asked.
    */
    'alpha_testing' => (bool) env('FEATURE_ALPHA_TESTING', false),
];
