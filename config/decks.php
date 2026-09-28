<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Onboard storage, in QB
    |--------------------------------------------------------------------------
    |
    | The FALLBACK for a deck with no figure of its own. Each deck carries its
    | own in `items.onboard_storage` — the base `deck_storage` starts from, so
    | modules and the Cyberdecks skill add to it — and a deck authored without
    | one holds this much rather than nothing.
    |
    */

    'onboard_storage' => 16,

];
