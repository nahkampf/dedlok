<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cyberspace
|--------------------------------------------------------------------------
|
| The shape of the grid and the words its systems are named from. CYBERSPACE.md
| is the design; this is the handful of numbers it names, in one place, because
| every one of them is a thing that will be tuned.
|
| The size is the load-bearing one. The grid is 64 x 64 today and the design is
| written against 2048 x 2048 — four million positions, where discovery stops
| being flavour and becomes the point of the map. Both sizes have to work, so
| nothing outside App\Cyberspace\Grid may assume either.
|
*/

return [

    /*
    | The edge of the square. 64 today, 2048 eventually.
    |
    | Everything derived from it is derived rather than written down: the
    | position count is size squared, and the portal sits at the middle by
    | division. A second number that has to be kept in step with this one is a
    | number that will not be.
    */
    'size' => 64,

    /*
    | How much of the grid holds a system, in percent.
    |
    | Roughly 30% of positions are EMPTY — not a dead node, not a placeholder,
    | just space. That reserve is what relocation and instantiation move
    | through: a grid with nowhere to churn to is a grid that stops churning,
    | and churn is what stops the map from being solved.
    |
    | Read as a target rather than a per-position chance. See App\Cyberspace\
    | GridSeeder, which tops the world up TO it the way the market tops a shelf
    | up to its target, so running it twice is not two worlds.
    */
    'occupancy' => 70,

    /*
    | How far a jacked-in character sees, in positions, as a Chebyshev radius —
    | a square window rather than a circle, because the grid is squares and a
    | round window on a square grid is a lie told in half-lit cells.
    |
    | The edges are edges and the grid does not wrap, so a window at the edge
    | simply sees fewer positions. The corners are the quietest part of the map,
    | which is a reasonable thing for the corners of a map to be.
    */
    'view_range' => 3,

    /*
    | How dangerous the world is, as relative weights per grade.
    |
    | Grade runs 1-9 and SPANS MAGNITUDES: a grade 1 is inside a starting
    | character's reach and a grade 9 can end somebody with 200 Synaptic
    | Integrity. A uniform roll would therefore make a third of the grid lethal
    | and the map an obstacle course. The world wants to be mostly ordinary
    | machines, with the occasional thing that should not be touched.
    |
    | Per mille, so they are readable as tenths of a percent and sum to 1000.
    | On a 64 x 64 grid at 70% occupancy that is roughly fourteen grade 9s in
    | the entire world - which is the point. They should be rumours.
    |
    | This is THE tuning dial for how dangerous cyberspace feels. Nothing
    | derives from it and nothing else has to be changed alongside it.
    */
    'grades' => [
        1 => 260,
        2 => 220,
        3 => 180,
        4 => 130,
        5 => 90,
        6 => 60,
        7 => 40,
        8 => 15,
        9 => 5,
    ],

    /*
    | How much of the map is DRAWN, as a Chebyshev radius. 10 is a 21 x 21
    | window.
    |
    | Not the same thing as `view_range`, and the difference is the whole point
    | of the screen: view_range is how far you can SEE right now, and this is
    | how much of your MEMORY is put on screen around you. The window drawn is
    | mostly ground you swept earlier, with the small square you can currently
    | see sitting fresh in the middle of it.
    |
    | It costs nothing but markup - a cell nobody has swept renders as a hole -
    | so it is a legibility number rather than a performance one. The map
    | scrolls to fit, and the player is kept in the centre of it.
    */
    'map_range' => 10,

    /*
    | How long a newly discovered machine stays marked as new, in minutes.
    |
    | Long enough to still be marked when a player walks back past it a moment
    | later; short enough that a stretch of grid does not stay lit up for a
    | whole session. The mark exists so that somebody who walks the same ground
    | often can spot the ONE machine that was not there last time — a map where
    | everything is permanently new says nothing.
    |
    | Counted from when the machine first appeared to that character, never
    | from when they last looked: looking again does not make a thing newer.
    */
    'new_for_minutes' => 10,

    /*
    | What a deck freezing costs the person inside it.
    |
    | A module breaks from thermal load mid-run, the deck stops being a working
    | machine, and the player is thrown out of cyberspace through the wall. See
    | CYBERSPACE.md, *Burning out a module ends the run*, for what happens and
    | in what order; these are the numbers it names.
    |
    | NOTHING READS ANY OF THIS YET. The run simulator is phase 5 and this is
    | phase 3 — these are here to be calibrated against each other and against
    | a survivable run length, which is an open question in CYBERSPACE.md. They
    | are a starting point, not a balance pass.
    |
    | Percentages, 0-100, like `occupancy` above.
    */
    'freeze' => [

        /*
        | Damage to CURRENT Synaptic Integrity, rolled per freeze.
        |
        | A share of what is left rather than a flat figure, because being
        | thrown out hurts in proportion to how much of you there was to throw.
        | Rounded the ordinary way — below 0.5 is 0 — so at 1 SI a low roll
        | does nothing at all.
        |
        | Worth knowing what this range can and cannot do: reaching 0 needs a
        | roll at or above `1 - 0.5/current`, which is 50% at 1 SI, 75% at 2,
        | and above 100% from 3 upwards. SO THIS CAN ONLY FINISH SOMEBODY WHO
        | WAS ALREADY AT 1 OR 2. That is deliberate — the flatline below is
        | what carries the real risk of a freeze, and it does not care how much
        | SI you had.
        */
        'si_damage' => ['min' => 25, 'max' => 75],

        /*
        | How often a freeze also flatlines, in percent.
        |
        | ONE NUMBER RATHER THAN THE 10-15 RANGE THE DESIGN SAID, because a
        | probability that is itself rolled uniformly is arithmetically just
        | its own mean — rolling 10-15 and then rolling against it is a flat
        | 12.5%. A range here would be two dice to say what one says. This is
        | the midpoint; move it, do not widen it.
        */
        'flatline_chance' => 12,

        /*
        | How much a flatline takes, as a percentage of MAXIMUM Synaptic
        | Integrity, rolled per flatline.
        |
        | An ICE flatline carries its amount on the program that inflicted it;
        | a freeze has no program, so it needs a number of its own and this is
        | it.
        |
        | Small on purpose. A flatline is PERMANENT and it ACCUMULATES — the
        | one that matters is the fourth one, not the first — so a single event
        | taking a fifth of somebody's ceiling would end characters rather than
        | mark them. This is a scar, and scars are supposed to add up.
        |
        | Permanent damage to maximum SI is an unexpiring modifier row and
        | never a subtraction into a column, because maximum SI is derived —
        | see MECHANICS.md, *Flatline is a permanent stored modifier*. The stat
        | floors at 1, so accumulated flatlines converge on a maximum of 1
        | rather than 0: they permanently cripple and never themselves kill,
        | which is the same rule that says injury can only ever be undone by a
        | clinic and never by arithmetic.
        */
        'flatline_amount' => ['min' => 5, 'max' => 15],

    ],

    /*
    | The serial on a generated name — the 041 of "Data silo - Anansi 041".
    |
    | A name is its type and its owner, and both of those are columns; this is
    | the one part of it that is stored, because it is the one part that is
    | nowhere else. EVERY generated machine gets one, unlike the word bag that
    | came before, which rolled a number onto 60% of names as texture. It is
    | not texture any more: 121 corps by three types is 363 combinations, so
    | without a serial a name identifies about eight machines at once.
    |
    | Rolled rather than counted. A counter would let a player read how many
    | machines a corp runs off the side of one of them.
    */
    'designation' => ['min' => 1, 'max' => 999],

];
