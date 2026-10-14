<?php

return [

    /*
    | Handles whose runs, scan passes and combat are written, in full, to
    | storage/logs/trace-YYYY-MM-DD.log (one file a day). Comma separated,
    | case-insensitive; empty is off. Set TRACE_HANDLES=Noodle in production.
    */
    'handles' => array_values(array_filter(array_map(
        static fn (string $h): string => mb_strtolower(trim($h)),
        explode(',', (string) env('TRACE_HANDLES', '')),
    ))),

    // Days of trace files kept.
    'days' => (int) env('TRACE_DAYS', 30),

];
