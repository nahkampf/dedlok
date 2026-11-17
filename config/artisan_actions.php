<?php

/*
|--------------------------------------------------------------------------
| Admin: Artisan actions
|--------------------------------------------------------------------------
|
| The commands the admin panel at /admin/artisan may run, with no arguments, in this order. A command not listed here
| cannot be run from the web, whatever is posted. The title is the command's name and the description is the command's own.
|
| ONLY COMMANDS THAT ARE SAFE TO RUN AT ANY MOMENT: setup, repair, reports, and sweeps that act on what has come due and so do
| nothing twice. Deliberately absent are the once-a-night ones (`bank:nightly`, `cyberspace:siphons`, `cyberspace:age-backdoors`,
| `characters:cool`, `standing:drift`, `drugs:metabolise`), because running one by hand is a second night; and everything that
| wants an argument (grants, flags, fights).
*/

return [
    'leaderboard:snapshot',
    'cyberspace:seed',
    'cyberspace:exchanges',
    'cyberspace:restock',
    'cyberspace:lockdowns',
    'cyberspace:settle',
    'market:seed',
    'city:stock-skills',
    'city:settle',
    'crafting:settle',
    'decryption:settle',
    'tracker:settle',
    'fortress:settle',
    'bank:sweep',
    'safehouses:rent',
    'missions:expire',
    'missions:retarget',
    'messages:deliver',
    'programs:expire',
    'supporters:expire',
    'chat:sweep-images',
    'bank:verify',
    'standing:verify',
    'skills:validate',
    'db:comments',
];
