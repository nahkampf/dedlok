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
| App\Checks\CheckRoller reads all of it, and is the only thing that does.
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

    /*
    | A KIND OF ROLL MAY NAME ITS OWN FLOOR, and this is the only place one can
    | be named.
    |
    | The floor is a principle — every roll in the game keeps it — and this is
    | the escape hatch for a mechanic that argues its way out of it. Keyed by
    | the kind a caller passes to the roller.
    |
    | IT IS CONFIG AND NEVER AN ARGUMENT, deliberately. An override passed at a
    | call site is a rule you can only find by reading every caller, and the
    | reasoning for it lives in a commit message nobody will read again; here it
    | sits next to the number, and the set of exceptions can be counted by
    | looking at one file. If this list ever grows past a handful, the floor has
    | stopped being a principle and that is worth noticing.
    |
    | Empty on purpose: nothing has argued its way out yet.
    */
    'floors' => [
        // 'detection' => 0.02,

        // THE FIRST EXCEPTION. Exploring has no floor, so a character far below
        // a Secretive place is hopeless against it rather than 0.85% — and the
        // same absence removes the ceiling, so a strong explorer is certain of
        // a Trivial one. See CITY.md.
        'exploration' => 0.0,
    ],

    /*
    | HOW DECISIVE THE GAME IS, IN ONE NUMBER.
    |
    | The margin — check value less difficulty — worth roughly three-quarters
    | odds. Every roll runs through the same logistic curve, so this is the
    | single dial between "skill decides everything" and "everything is a coin
    | flip":
    |
    |   spread 4   +8 margin is 88%, +16 is 98%    skill dominates
    |   spread 8   +8 margin is 73%, +16 is 88%    the default
    |   spread 16  +8 margin is 62%, +16 is 73%    luck matters everywhere
    |
    | Equal value and difficulty is 50% whatever this is set to, which is the
    | property that makes a difficulty mean something: it is the level at which
    | a character is evenly matched.
    |
    | Unmeasured. Nothing has been balanced against it because nothing rolls
    | yet — 8 is a starting point chosen so that the attribute-and-skill numbers
    | the game already produces land across a usable part of the curve.
    */
    'spread' => 8.0,

];
