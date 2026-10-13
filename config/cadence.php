<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| When the clocks tick
|--------------------------------------------------------------------------
|
| The cron expressions of the scheduled jobs whose NEXT moment a screen has to tell a player (the Current processes modal).
| `routes/console.php` reads them for the schedule and the modal reads them for the countdown, so the two cannot disagree. A
| job nobody counts down to keeps its expression where it is scheduled.
*/
return [
    // The market's restock pass: every eight hours, on the hour.
    'market_seed' => '0 */8 * * *',

    // The nightly day off every drug book: addiction and tolerance fall a step, and a withdrawal that falls under its line ends.
    'drugs_metabolise' => '20 5 * * *',

    // The nightly cooling of heat (`characters:cool`), ten minutes behind the bounty roll. The character sheet's heat box
    // counts down to it.
    'heat_cool' => '10 5 * * *',
];
