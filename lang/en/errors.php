<?php

declare(strict_types=1);

/*
| Framework-level failures that reach the player. Laravel hardcodes several of
| these as English literals inside vendor code (ThrottleRequests throws a raw
| 'Too Many Attempts.'), so they are re-stated here and swapped in by the
| exception renderers in bootstrap/app.php.
*/

return [
    'throttle' => [
        'title' => 'Slow down',
        'body' => 'Too many requests. Try again in :seconds seconds.',
    ],
];
