<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Software
|--------------------------------------------------------------------------
|
| The rules that apply to running programs rather than to any one of them.
| What a particular program costs and does is on its row — see the `programs`
| table and the item editor.
|
*/

return [

    /*
    | HOW MANY INSTANCES OF ONE PROGRAM A CHARACTER MAY RUN AT ONCE.
    |
    | One. A new character runs a single copy of anything, and that is the
    | whole of what they can do until they train for more — which makes the
    | first level of Multithreading a real change in what an evening looks
    | like rather than a percentage on a number.
    |
    | The base of the `program_threads` stat, so skills and hardware raise it
    | like anything else. It is a CEILING PER PROGRAM and not a total: two
    | threads means two scanners or two decryptors, not two processes.
    |
    | A program whose row does not say `multithreaded` is capped at one
    | whatever this says. Some software cannot be pointed at two things at
    | once, and no amount of skill should make an icebreaker fight two pieces
    | of ICE.
    */
    'threads' => 1,

];
