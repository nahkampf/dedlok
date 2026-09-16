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
    |
    | TWO, so a bare character sees a 5 x 5 square. It is deliberately small:
    | walking is what buys ground, and a wide default would hand a new player
    | most of a neighbourhood for standing still. It is also exactly what a
    | 360px phone shows at a 60px cell, so the lit square fits a screen without
    | anybody scrolling to find the edge of their own sight.
    */
    'view_range' => 2,

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
    | How much of the map is DRAWN, as a Chebyshev radius. 7 is a 15 x 15
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
    /*
    | CAPPED AT WHAT A CHARACTER COULD EVER SEE. `view_range` is 2 and the
    | modifiers that will widen it are worth about +5 between them, so seven is
    | the furthest anybody will ever see in one look — and drawing memory
    | further out than that is drawing ground the player has no way to refresh
    | from where they stand.
    |
    | Raise it if sight gets cheaper. The two numbers are related but not the
    | same one: this is how much is DRAWN and view_range is how much is LIT.
    */
    'map_range' => 7,

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
    | WHAT A NODE IS HOLDING, and how much of it.
    |
    | Everything here scales with grade, because grade is the promise a scan
    | makes: the machine that can end you is the machine worth breaking into.
    | The numbers are a starting point and nothing has been balanced against
    | them — nothing can raid a node yet.
    |
    | CREDS are the floor of a haul: always something, rarely much. A grade 1
    | is pocket money and a grade 9 is a month's rent, which is roughly the
    | span the rest of the economy will have to live with.
    |
    | A FILE is the real prize and is not guaranteed, so a raid is a gamble on
    | more than getting out alive. Its SIZE costs turns on the way out, its
    | ENCRYPTION is drawn from the ladder those four skills form — a grade 9
    | seals things with something almost nobody can open — and it is worth more
    | broken than sealed, which is the whole of why decryption exists.
    |
    | An ITEM is the rarest of the three. What a node SHOULD hold by type — a
    | data silo full of files, a compute node full of creds — is a loot table
    | and wants its own pass; the weighting below is the crude version of it.
    */
    'contents' => [

        // Creds per grade: [min, max]. Rolled once per restock.
        'creds' => [
            1 => [40, 120],
            2 => [80, 240],
            3 => [150, 450],
            4 => [300, 800],
            5 => [600, 1500],
            6 => [1000, 2600],
            7 => [1800, 4200],
            8 => [3000, 7000],
            9 => [5000, 12000],
        ],

        /*
        | How likely a node is holding a file at all, per mille, and what it
        | looks like if it is.
        |
        | `size_qb` is what carrying it out costs in turns; `value` is what it
        | fetches SEALED, and `broken_multiplier` is what breaking it is worth
        | — the number that makes decryption a decision rather than a chore.
        */
        'data' => [
            'chance' => [
                1 => 250, 2 => 300, 3 => 350, 4 => 420, 5 => 500,
                6 => 600, 7 => 700, 8 => 800, 9 => 900,
            ],
            'size_qb' => [
                1 => [2, 6], 2 => [4, 10], 3 => [6, 16], 4 => [10, 24], 5 => [16, 40],
                6 => [24, 60], 7 => [40, 90], 8 => [60, 140], 9 => [90, 220],
            ],
            'value' => [
                1 => [120, 400], 2 => [250, 800], 3 => [500, 1600], 4 => [900, 2800], 5 => [1600, 5000],
                6 => [2800, 8000], 7 => [4500, 13000], 8 => [7500, 21000], 9 => [12000, 34000],
            ],
            // Per mille of the sealed price, so 2200 means broken is worth
            // 2.2x what a buyer gives for it still sealed.
            'broken_multiplier' => 2200,
        ],

        /*
        | Items: how many, rather than whether.
        |
        | A node holds NONE TO SEVERAL. `chance` is the per-mille odds of the
        | first one and of each one after it, so a grade 1 usually holds
        | nothing and a grade 9 often holds two — the draw repeats until it
        | misses or hits `max`, which is what makes a rich machine feel rich
        | rather than merely likely.
        |
        | A machine with a `stock_rule` ignores all of this: it holds what it
        | was told to hold. See the migration that added the column.
        */
        'item' => [
            'chance' => [
                1 => 100, 2 => 130, 3 => 170, 4 => 220, 5 => 280,
                6 => 350, 7 => 430, 8 => 520, 9 => 620,
            ],
            // A ceiling on the repeat, so a lucky run of rolls cannot empty
            // the catalogue into one box.
            'max' => 4,
        ],

        /*
        | What each type of machine leans towards, per mille applied to the
        | chances above. A silo keeps files; a compute node moves money; a
        | relay carries a bit of everything and not much of it.
        */
        'by_type' => [
            'silo' => ['data' => 1500, 'item' => 900, 'creds' => 700],
            'compute' => ['data' => 700, 'item' => 900, 'creds' => 1400],
            'relay' => ['data' => 900, 'item' => 1300, 'creds' => 900],
        ],
    ],

    /*
    | Scanning: what it takes to find out what a machine is.
    |
    | A scan answers the question a grade poses. The difficulty is per grade
    | rather than a formula, for the reason the grade weights are a list: this
    | is a tuning dial somebody will want to bend in the middle without
    | rewriting either end.
    |
    | The numbers are against `check.systemprofiling`, which runs from about 15
    | for a fresh character to the high sixties for a trained one with the
    | attributes for it. So a beginner reads the bottom of the grid reliably,
    | the middle with a trained specialist, and the top not at all — which is
    | what makes a grade 9 a rumour rather than a target.
    |
    | PASSES is the other half of it. One attempt is one roll per thing not yet
    | known, and a scan is allowed this many attempts before the picture it has
    | is the picture it gets. That is the 1-5 turns CYBERSPACE.md budgets for
    | scanning inside a run, and it is what stops a free act from being ground
    | until it succeeds: a failed scan costs the expiry window, not a retry.
    |
    | LIFETIME is how long the answer stays true, in hours. A machine that has
    | been relocated, patched or raided since is described by a file that no
    | longer matches it.
    */
    /*
    |--------------------------------------------------------------------------
    | What a machine defends itself with
    |--------------------------------------------------------------------------
    |
    | Three countermeasures, and EVERY HOSTILE THING CAN NOTICE YOU — not just
    | the intrusion detection. The IDS is far better at it, but a tracer and an
    | ICE each carry a detection value of their own, which is why a machine with
    | no IDS is quieter rather than safe. See CYBERSPACE.md, *Detection*.
    |
    | WHAT A MACHINE CARRIES IS DECIDED ONCE, AT CREATION, AND STORED. Type sets
    | the floor and grade rolls the rest, and the answer lands in columns on the
    | row rather than being derived on read. That is what makes a mission node
    | free: a machine that has to be dangerous is a row with different numbers
    | in the same columns, needing no rule, no override and no special case.
    | `stock_rule` exists because a node's CONTENTS are re-rolled nightly;
    | defences never are.
    |
    | The portal carries nothing, and that needs no entry here —
    | SystemType::isInteractable() already says no to everything for it.
    */
    /*
    | HOW MANY OTHER RUNNERS A CHARACTER MAY TAKE INTO A SYSTEM.
    |
    | None. Calling a cooperative run is a thing you train for — Coordinated
    | Strike and then Advanced Coordination, a slot per level each — and a
    | character who has neither runs alone. That is the ordinary state rather
    | than a penalty, the same shape as owning no icebreaker.
    |
    | The base of the `coop_slots` stat, floored at zero rather than one, so
    | zero is both the default and the gate. Both skills trained out is ten
    | others, which is overkill for anything currently on the grid and is meant
    | to be: it is a ceiling built for machines that do not exist yet.
    |
    | Nothing reads it. Cooperative runs are phase 7.
    */
    'coop' => [
        'slots' => 0,
    ],

    'defences' => [

        /*
        | WHAT EACH KIND OF MACHINE ALWAYS HAS.
        |
        | A rotation: every type guarantees two countermeasures and rolls for
        | the third, and every countermeasure is guaranteed by two types and
        | rolled by one. So the thing a scan is for is never "does this have
        | anything" — it is which of the three it happens to be missing.
        |
        | The compute node is the machine CYBERSPACE.md describes when it says a
        | system with no IDS but with ICE is real and ordinary: it is the one
        | type whose detection is the thing left to chance.
        */
        'always' => [
            'silo' => ['ids', 'ice'],
            'relay' => ['ids', 'tracer'],
            'compute' => ['ice', 'tracer'],
        ],

        /*
        | THE CHANCE OF CARRYING THE ONE ITS TYPE DOES NOT GUARANTEE, per cent.
        |
        | Never zero and never certain, for the reason no check resolves to a
        | certainty: a grade 1 that happens to have a tracer is the machine that
        | teaches somebody to scan, and a grade 9 without one is the rumour
        | worth chasing.
        */
        'presence' => [
            1 => 10,
            2 => 18,
            3 => 26,
            4 => 36,
            5 => 46,
            6 => 58,
            7 => 70,
            8 => 82,
            9 => 95,
        ],

        /*
        | HOW HARD THE INTRUSION DETECTION IS TO GET PAST.
        |
        | A difficulty handed to App\Checks\CheckRoller, so it is directly
        | comparable to `scan.difficulty` below — and deliberately the same
        | table. Reading a machine and hiding from its watchdog being equally
        | hard at a given grade is an anchor a designer can hold in their head,
        | and one curve to tune beats two that drift apart.
        |
        | Against a mid-career runner (a check around 51, at the default spread)
        | that is the certainty floor at grade 1, about three in ten at grade 5,
        | and the floor at the other end by grade 9 — which is exactly the
        | "trips a household IDS about once in every hundred and twenty
        | attempts" CYBERSPACE.md claims for the bottom of the range.
        */
        'ids' => [
            1 => 10,
            2 => 18,
            3 => 26,
            4 => 34,
            5 => 44,
            6 => 54,
            7 => 66,
            8 => 78,
            9 => 92,
        ],

        'tracer' => [

            /*
            | WHAT A TRACER NOTICES, and it is MUCH LOWER than an IDS at the
            | same grade — a fifth of it at the bottom, widening to a little
            | over two-fifths at the top.
            |
            | Shaped rather than a flat fraction, because the curve says
            | something: a cheap tracer is following traffic and not looking for
            | anybody, and an expensive one is doing both. Up to grade 4 it sits
            | on the certainty floor against a competent runner and genuinely
            | never notices them, which is what keeps the bottom of the grid
            | walkable; from grade 7 it is a second pair of eyes, and at grade 9
            | it catches a good runner about one approach in five.
            */
            'detection' => [
                1 => 2,
                2 => 4,
                3 => 7,
                4 => 10,
                5 => 14,
                6 => 19,
                7 => 25,
                8 => 32,
                9 => 40,
            ],

            /*
            | HOW FAST IT TRACES, as per cent of a completed trace per turn.
            |
            | Calibrated against the one number the design already fixes: a run
            | is 4-20 turns. A grade 1 tracer needs twenty-five turns and so
            | never lands inside an ordinary run; a grade 9 completes in five,
            | which is faster than most hauls. The curve is the brake, not a
            | threshold somewhere else.
            |
            | NOTHING READS THIS YET. The trace advances per turn and there is
            | no turn loop — see CYBERSPACE.md, *The fight*.
            */
            'rate' => [
                1 => 4,
                2 => 5,
                3 => 6,
                4 => 8,
                5 => 10,
                6 => 12,
                7 => 15,
                8 => 18,
                9 => 22,
            ],
        ],
    ],

    'scan' => [
        'difficulty' => [
            1 => 10,
            2 => 18,
            3 => 26,
            4 => 34,
            5 => 44,
            6 => 54,
            7 => 66,
            8 => 78,
            9 => 92,
        ],
        'passes' => 5,

        /*
        | HOW LONG ONE PASS TAKES, in minutes, for somebody with no skill at
        | all. A grade 1 box gives itself up in about a minute; a grade 9 takes
        | the better part of a quarter of an hour, and there are five passes in
        | a full read of one.
        |
        | That is the point of the curve: reading the bottom of the grid is
        | something you do in passing, and reading the top is an evening's work
        | somebody has to decide to spend. It is also the first thing in the
        | game that makes standing still in cyberspace cost anything.
        |
        | Skill and software cut it, through the `scan_duration` stat — a
        | trained profiler with a good instrument reads a grade 9 in half this.
        */
        'minutes' => [
            1 => 1,
            2 => 2,
            3 => 3,
            4 => 4,
            5 => 6,
            6 => 8,
            7 => 10,
            8 => 13,
            9 => 16,
        ],

        'lifetime_hours' => 48,

        /*
        | The skill a scan is read with, by slug.
        |
        | Here rather than in the Probe because it names a ROW: System Profiling
        | is content, it has a book and a tree position, and a game that decided
        | to read machines with something else should not need a deploy.
        */
        'skill' => 'systemprofiling',
    ],

    /*
    | What a deck that stops running costs the person standing in it.
    |
    | Not a burnout and not a defeat: the machine simply stopped meeting its
    | own requirements while somebody was inside it — a buff expired, a drug
    | wore off, an injury landed. They are thrown out and it costs them this
    | much Synaptic Integrity. See App\Cyberspace\ReadinessWatch.
    |
    | SMALL BECAUSE THE CAUSE IS USUALLY NOT THEIRS, and not zero because being
    | dumped out of cyberspace should never be free. One point carries no
    | defeat effect of its own — no ICE was involved, so nothing flatlines,
    | surges or erases.
    |
    | This one IS read, unlike the freeze numbers below it.
    */
    'readiness' => [
        'damage' => 1,
    ],

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
        | WHICH FLATLINE A FREEZE DEALS, by level. See `flatline` below.
        |
        | The mildest one there is, and that is a statement rather than a
        | default: a thermal burnout is an ACCIDENT, and it should never take
        | more of somebody's ceiling than software built on purpose to do it.
        |
        | It used to carry its own 5-15% here, because it was the only flatline
        | in the game and had nowhere else to get a number. Levels gave it
        | somewhere, and one vocabulary beats a number that can drift away from
        | whatever an ICE's Flatline I turns out to mean.
        */
        'flatline_level' => 1,

    ],

    /*
    |--------------------------------------------------------------------------
    | How much a flatline takes
    |--------------------------------------------------------------------------
    |
    | As a percentage of MAXIMUM Synaptic Integrity, rolled per flatline, by
    | the level of the Flatline the program carried. An ICE says which level it
    | inflicts; a freeze says the same thing through `freeze.flatline_level`.
    |
    | SMALL ON PURPOSE, ALL THREE OF THEM. A flatline is PERMANENT and it
    | ACCUMULATES — the one that matters is the fourth, not the first — so a
    | single event taking a fifth of somebody's ceiling would end characters
    | rather than mark them. **Nothing here reaches 20%, and that is the rule
    | rather than where the numbers happened to land.** A grade 9's ICE is
    | terrifying because it carries Flatline III AND Surge III AND Neural
        | Shock, not
    | because any one roll is enormous — which is what several tags on one
    | program are FOR.
    |
    | Permanent damage to maximum SI is an unexpiring modifier row and never a
    | subtraction into a column, because maximum SI is derived — see
    | MECHANICS.md, *Flatline is a permanent stored modifier*. The stat floors
    | at 1, so accumulated flatlines converge on a maximum of 1 rather than 0:
    | they permanently cripple and never themselves kill, the same rule that
    | says injury is undone by a clinic and never by arithmetic.
    |
    | Three levels is enough. A fourth would be splitting a range that is only
    | fifteen points wide, and the difference between III and a hypothetical IV
    | would be invisible to the person it happened to.
    */
    'defeat_tags' => [

        /*
        | FLATLINE — permanent damage to MAXIMUM Synaptic Integrity, rolled.
        |
        | Small on purpose, all three. A flatline is PERMANENT and it
        | ACCUMULATES — the one that matters is the fourth, not the first — so a
        | single event taking a fifth of somebody's ceiling would end characters
        | rather than mark them. NOTHING HERE REACHES 20%, and that is the rule
        | rather than where the numbers happened to land: a grade 9's ICE is
        | terrifying for carrying Flatline III AND Surge III AND Neural Shock,
        | which is
        | what several tags on one program are FOR.
        |
        | Permanent damage to maximum SI is an unexpiring modifier row and never
        | a subtraction into a column, because maximum SI is derived — see
        | MECHANICS.md. The stat floors at 1, so accumulated flatlines converge
        | on a maximum of 1 rather than 0: they cripple and never themselves
        | kill.
        |
        | Three levels is enough. The usable range is fifteen points wide and a
        | fourth rung would be invisible to the person it happened to.
        */
        'flatline' => [
            1 => ['min' => 4, 'max' => 10],
            2 => ['min' => 9, 'max' => 16],
            3 => ['min' => 14, 'max' => 19],
        ],

        /*
        | SKIM — how much of what you were CARRYING is taken, per cent.
        |
        | Carried, never stored: what is in a node is beyond anything a defeat
        | can reach, and so is anything a character has not got on them. That is
        | the rule that makes a node worth coming back to and a rich runner
        | worth robbing.
        |
        | Steep on purpose. Ten per cent is a fee, sixty is a reason to bank
        | before a run — which is the decision the tag exists to create, and it
        | is one a player can actually act on, unlike a flatline.
        */
        'skim' => [
            1 => ['share' => 10],
            2 => ['share' => 30],
            3 => ['share' => 60],
        ],

        /*
        | ERASE — how much of the deck's software and data goes.
        |
        | `count` is exactly that many, drawn at random; `share` is that
        | fraction of what is held. Two keys rather than one clever number,
        | because "1" meaning a count and "0.5" meaning a share is the kind of
        | type-punning that reads fine today and wrongly at three in the
        | morning.
        |
        | Level three wipes the deck — every program and every file on it. That
        | is the most expensive thing in the tag set by some distance, and it
        | belongs only on ICE nobody should be fighting by accident.
        */
        'erase' => [
            1 => ['count' => 1, 'share' => null],
            2 => ['count' => null, 'share' => 50],
            3 => ['count' => null, 'share' => 100],
        ],

        /*
        | NEURAL SHOCK — damage to CURRENT HIT POINTS, as a percentage of
        | maximum, rolled.
        |
        | It was called Drain and aimed at Synaptic Integrity, which could never
        | have worked: a tag fires BECAUSE Synaptic Integrity reached zero, and
        | subtracting from zero is nothing. Aiming it at the body is what the
        | mechanic was reaching for all along — the feedback comes back down the
        | interface and stops a heart rather than a thought.
        |
        | It touches neither maximum. That is Flatline's job, and permanent.
        |
        | Scaled against a new character's 30 hit points, so Neural Shock III is
        | a third to a half of somebody's body — enough to finish a runner who
        | walked in hurt, and not enough to be an execution on its own.
        */
        'neural_shock' => [
            1 => ['min' => 8, 'max' => 15],
            2 => ['min' => 15, 'max' => 28],
            3 => ['min' => 28, 'max' => 45],
        ],

        /*
        | SURGE — destroys fitted hardware, at random.
        |
        | `chance` IS ROLLED ONCE PER MODULE, not once for the tag. Surge II is
        | two separate 45% rolls, so it usually takes one and sometimes takes
        | two or none — which is a very different feel from one roll that either
        | costs you two modules or costs you nothing, and it is the shape that
        | makes the level read as *how much is at stake* rather than *how likely
        | a disaster is*.
        |
        | The jump from II to III is in BOTH numbers, which is why the top rung
        | is a step rather than a slope: three rolls at 55% averages 1.65
        | modules against II's 0.9.
        |
        | It does not stack with itself — a piece of ICE carries one Surge, and
        | the level is which one.
        |
        | NOTHING IS SPARED FOR HAVING LITTLE FITTED. A deck that is not ready
        | cannot be in cyberspace at all — DeckRequirement wants compute, memory,
        | link and power — so anybody a Surge can reach has modules to lose, and
        | the tag never needs a special case for an empty deck.
        |
        | Random, and that is the contrast with a thermal burnout: the burnout
        | takes the thermally weakest module deterministically, because the
        | player was warned about it. ICE does not know or care which part is
        | closest to its limit, so watching your heat buys nothing here.
        */
        /*
        | BOUNTY — somebody puts a price on the character.
        |
        | AN AUTHORING CHANCE, NOT A ROLL AT DEFEAT. Bounty is an ordinary tag
        | that sits on a piece of ICE like any other — what is unusual is how it
        | got there: rather than being chosen per piece, it is rolled once when
        | the catalogue is written, at these odds.
        |
        | So a given piece of ICE either carries it or does not, permanently and
        | visibly on its row. Low-level ICE rarely does; high-level ICE usually
        | does and sometimes does not, which is the point — a player who has met
        | one grade 9 guard has learned nothing certain about the next.
        |
        | Keyed by the ICE's LEVEL rather than by grade, because the tag lives
        | on the piece and a piece spans a band. The ends are the ones the design
        | asks for — a tenth at the bottom, two-thirds at the top — and the
        | middle is the straight line between them.
        |
        | Read once, by the pass that authors the catalogue. Editing a row
        | afterwards is how a piece stops agreeing with this table, and that is
        | allowed: the table generates a starting distribution, it does not own
        | the rows.
        |
        | The mechanic a bounty turns into does not exist. What exists is which
        | pieces of ICE will trigger it.
        */
        'bounty' => [
            1 => 10,
            2 => 15,
            3 => 20,
            4 => 25,
            5 => 30,
            6 => 35,
            7 => 40,
            8 => 45,
            9 => 50,
            10 => 55,
            11 => 60,
            12 => 65,
        ],

        'surge' => [
            1 => ['chance' => 45, 'modules' => 1],
            2 => ['chance' => 45, 'modules' => 2],
            3 => ['chance' => 55, 'modules' => 3],
        ],
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
