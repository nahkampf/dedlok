<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Checks
|--------------------------------------------------------------------------
|
| The rules that govern a ROLL, as opposed to the rules that govern a number.
| App\Stats\Resolver has no randomness in it by design — it turns bases and
| modifiers into a value and stops. What happens after that, when something
| has to succeed or fail, is decided here.
|
| Nothing reads this file yet. The check layer is specified only; see
| MECHANICS.md.
|
*/

return [

    /*
    | NO CHECK RESOLVES TO A CERTAINTY.
    |
    | Every roll in the game keeps this much probability at each end, however
    | good the character and however trivial the opposition. The best runner
    | alive still trips a household IDS about once in thirty attempts, and the
    | worst one still gets past a military firewall now and then.
    |
    | The reasoning is not fairness, it is that a mechanic which can be reduced
    | to a certainty stops being a mechanic: a loop with no failure state is a
    | loop that gets farmed, and cyberspace has several loops that would be.
    |
    | A float, because this is exactly the kind of number that gets tuned to
    | 0.0289 one afternoon and should not need a migration to do it.
    |
    | Note that this compounds. A run making four floored rolls is clean
    | 0.9915^4 = 96.6% of the time, not 99.15%, and three such runs — what a
    | system's raid counter allows — come to 90.3%. So the felt risk of a run
    | is a function of how many rolls it makes, and that count is a design
    | decision rather than an accident of how the simulator is structured.
    */
    'certainty_floor' => 0.0085,

];
