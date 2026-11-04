<?php

declare(strict_types=1);

/*
| The Assessor (docs/ASSESSOR.md): rough odds from what a scan has read.
*/
return [

    // Unnoticed at least this often and an ICE is assumed to stay asleep, so the run is judged as if there were none.
    // Below it, with no icebreaker running, the ICE will probably wake and cannot be beaten: the overall is p x the no-ICE chance.
    'unnoticed_trusted' => 0.90,

    // Runs in the estimate, and the longest one it plays out. The die is a seeded private generator, so one scan
    // and one character always give the same number.
    'runs' => 400,
    'max_turns' => 40,

    // Extra turns spent pulling a file, each one a chance for the IDS alone to notice. A file's size is not in a scan.
    'assumed_pulls' => 1,

];
