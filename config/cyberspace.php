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
    | them — a raid is built, and what a haul is worth against the rest of the
    | economy is still nobody's decided answer.
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
            // What it fetches SEALED, and what it fetches once BROKEN: [min, max] creds, rolled
            // independently, so a lucky sealed file can out-earn a poor decrypted one. Geometric
            // between grade 1 (25-50 / 50-125) and grade 9 (1000-2000 / 2000-5400).
            'value' => [
                1 => [25, 50], 2 => [40, 80], 3 => [65, 125], 4 => [100, 200], 5 => [160, 315],
                6 => [250, 500], 7 => [400, 795], 8 => [630, 1260], 9 => [1000, 2000],
            ],
            'value_broken' => [
                1 => [50, 125], 2 => [80, 200], 3 => [125, 320], 4 => [200, 515], 5 => [315, 820],
                6 => [500, 1315], 7 => [795, 2105], 8 => [1260, 3375], 9 => [2000, 5400],
            ],
            // Which encryption a file may carry: each Encryption skill's slug and the [lowest, highest]
            // grade of system it turns up in. Where bands overlap, the draw is even between the
            // eligible ones; a skill with no entry here is eligible everywhere.
            'encryption' => [
                'cinder' => [1, 4],
                'halcyon3' => [3, 6],
                'blackglass' => [6, 8],
                'ouroboros' => [8, 9],
            ],
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
    |--------------------------------------------------------------------------
    | Decryption
    |--------------------------------------------------------------------------
    |
    | A stolen file is broken AT LEISURE, as a queue: one file at a time, in cycles. At the end of each whole cycle
    | during which a Decryptor program has been running on the equipped deck, ONE check is rolled — the file's
    | encryption skill (`check.<slug>`, with whatever the running decryptor adds) against the difficulty below. A
    | pass opens the file; a fail costs only the cycle, and nothing carries forward.
    |
    | `difficulty` is per encryption, keyed by the skill's slug. Against `check.<slug>` of about 15 for a fresh
    | character who has just injected the skill, about 51 mid-career and about 67 fully trained, so each
    | encryption is a coin flip for somebody a rung or two above where it turns up. `checks.floors.decryption`
    | keeps a hopeless matchup slow rather than impossible.
    |
    */
    'decryption' => [
        // Skill needed to attempt each encryption, on the RESOLVED level (`skill.<slug>`): level 0 is injected but untrained.
        // A slug left out needs level 1.
        'gate' => [
            'cinder' => 1,
            'halcyon3' => 1,
            'blackglass' => 1,
            'ouroboros' => 1,
        ],
        // Files waiting, the one being worked on included.
        'queue_max' => 10,
        'difficulty' => [
            'cinder' => 20,
            'halcyon3' => 35,
            'blackglass' => 50,
            'ouroboros' => 65,
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
    | LIFETIME is the other half of it, and it is the whole budget: how long
    | the answer stays true, in hours, and therefore how long an instrument may
    | keep going round at a machine it cannot read. There is no pass count. A
    | failed scan costs the window and an analyser's compute for the length of
    | it, which is what stops a free act from being ground until it succeeds.
    | A machine that has been relocated, patched or raided since is described
    | by a file that no longer matches it.
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
    | LEAVING. Always possible, never certain.
    |
    | Nothing can take the attempt away — not an active ICE, not a wrong
    | operating system, not a trace about to land — because a run with no exit
    | is a character deleted by a door they could not see. What varies is the
    | CHANCE, and a failed attempt costs the turn: the machine still gets its
    | half of it, so being held is paid for in trace, heat and one more swing.
    |
    | The value is the `egress` stat, a POOL of three attributes resolved
    | through modifiers like everything else — so a skill or a program that
    | helps somebody leave is a row and never a branch in the simulator. See
    | config/stats.php.
    |
    | DIFFICULTY IS THE GRADE PLUS WHAT IS FIGHTING YOU, which is CYBERSPACE.md
    | open question 12 resolved: tearing free mid-fight is not the same act as
    | walking out of a quiet machine, and an ICE that never woke is a cheap
    | exit — one more thing an undetected run is worth.
    |
    | Against a starting character (the pool lands near 14) and the global
    | spread of 8, the table below reads roughly:
    |
    |   grade 1, nothing awake      80%     out in a turn and a bit
    |   grade 5, ICE V awake        44%     a real decision
    |   grade 9, ICE XII awake      12%     you should not have been there
    |
    | `ice_term` is the multiplier on the ICE's LEVEL, not on its attack. What
    | makes a guard hard to escape is how good it is, and level is the one
    | number that says that about a piece of ICE without being about how hard
    | it hits.
    */
    'egress' => [

        // Difficulty per system grade, before anything fighting you is added.
        'difficulty' => [
            1 => 3,
            2 => 5,
            3 => 7,
            4 => 9,
            5 => 11,
            6 => 13,
            7 => 15,
            8 => 17,
            9 => 19,
        ],

        // Added per level of the ICE currently awake. 1 means ICE VII adds 7.
        'ice_term' => 1,
    ],

    /*
    | WHAT A PIECE OF ICE DOES WHILE IT IS FIGHTING.
    |
    | The mirror of `defeat_tags`, and the difference is when they matter: a
    | defeat tag fires once, on somebody who already lost, while these are in
    | force for as long as the thing is awake and alive. One changes the price
    | of losing; the other changes the decisions inside the fight.
    |
    | ENSNARE multiplies the egress chance. A MULTIPLIER rather than a
    | subtraction because every other percentage in the game is one — a flat 55
    | points off would annihilate a chance that was already poor and barely
    | dent a good one, which is backwards. A quarter off a bad chance is still
    | a bad chance; a quarter off a good one is a decision.
    |
    | It is floored again after the reduction, so even Ensnare III leaves the
    | door open. What it costs is turns, and turns are trace and heat.
    |
    | Passive while the ICE lives, so breaking the guard is the way out from
    | under it — which is what makes cutting worth doing to somebody whose only
    | plan was to leave.
    */
    'attack_tags' => [

        // Per cent off the egress chance, per level.
        'ensnare' => [
            1 => ['reduction' => 25],
            2 => ['reduction' => 40],
            3 => ['reduction' => 55],
        ],
    ],

    /*
    | HOW A BACKDOOR IS LOST.
    |
    | A backdoor keeps a system open — no lockdown, no relocation, a permanent
    | address in a world where nothing else has one. That is a genuinely
    | powerful thing to hold, so it is not held for ever, and this is the only
    | thing that takes one from somebody who does nothing wrong.
    |
    | ONE ROLL PER BACKDOOR PER NIGHT, and the odds rise with its AGE and with
    | nothing else. Not with the machine's grade, not with what has been done
    | through it — age alone, so a backdoor is a wasting asset rather than a
    | thing you can play carefully enough to keep.
    |
    |   chance = base + (days held x per_day), capped
    |
    | 1% on the first night, climbing about a point and a half a month, capped
    | at a tenth. That puts the median life of a backdoor somewhere near six
    | weeks: long enough that installing one is worth the run, short enough
    | that a portfolio has to be maintained rather than accumulated. Nothing
    | has been balanced against it — like every number a raid touches — and it
    | is the first dial to turn if backdoors feel disposable or eternal.
    |
    | A hit REMOVES THE BACKDOOR AND MOVES THE MACHINE: somebody found the way
    | in, and the machine they found it on does not stay where it was. That
    | takes every OTHER backdoor on the same machine with it, which is correct
    | — they were all doors into a box that has moved.
    */
    /*
    |--------------------------------------------------------------------------
    | Exchanges — an entity's front desks
    |--------------------------------------------------------------------------
    |
    | An Exchange is the one system nothing can be done TO: no run, no probe,
    | no burn, no lockdown and no relocation. What it offers is missions and a
    | loyalty store, and both read the gate below.
    |
    | HOW MANY DESKS AN ENTITY KEEPS is authored rather than rolled. Which
    | organisation runs a grade 5 desk is a fact about the world, and a world
    | that re-rolled it every pass would be a different world each time. An
    | entity with no entry keeps `default_grades` of them, from grade 1 up.
    |
    | THE GATE IS STANDING AND NOT DISTANCE. Higher grades sit deeper, so
    | getting there is a journey; being served when you arrive is a question
    | about who you are. `min_standing` is copied onto each desk as it is
    | placed, so retuning this moves the NEXT world rather than this one.
    |
    */

    'exchanges' => [
        'default_grades' => 3,

        'grades' => [
            // The only grade 5 desk on the grid, and deliberately so: a fund
            // large enough to be weather is the one that can afford it.
            'Khaleej Sovereign Holdings' => 5,
            'Ninth Circle Security' => 4,
            'Anansi Data Weaving' => 4,
            'Joint Signals Command' => 4,
            'Bright Verge Media' => 3,
            'Ivory Registry' => 3,
            'Bureau of Network Integrity' => 3,
            'Directorate of Revenue Enforcement' => 2,
            'Lockjaw' => 3,
            'Golden Path' => 2,
        ],

        // What each grade of desk wants to think of somebody first. The lower
        // three are open to anybody not in the red; the top two have to be
        // earned, which is what stops standing being only a store gate.
        'gate' => [
            1 => 0,
            2 => 5,
            3 => 20,
            4 => 40,
            5 => 60,
        ],

        // Agents are people, so they have names. Two lists and a roll: the
        // point is that the desk is answered by somebody rather than by a
        // department, not that the name means anything.
        'agents' => [
            'first' => [
                'Ilse', 'Marek', 'Oyelaran', 'Tam', 'Rusalka', 'Benedikt', 'Nadia', 'Osric',
                'Yusra', 'Dov', 'Lenka', 'Amadou', 'Pilar', 'Torsten', 'Wren', 'Kiyoshi',
            ],
            'last' => [
                'Achebe', 'Varga', 'Okonkwo', 'Lindqvist', 'Baptiste', 'Moreau', 'Sandoval',
                'Ferreira', 'Halloran', 'Novak', 'Rasmussen', 'Adeyemi', 'Castellanos', 'Aoki',
            ],
        ],
    ],

    'backdoors' => [

        /*
        | WHAT A SIPHON PAYS, per night, per backdoor.
        |
        | (3000 x grade) / 100 — thirty creds a grade, so a grade 1 relay is
        | pocket change at 30 and a grade 9 is 270 a night for doing nothing.
        | Against a node's own haul (a grade 9 holds hundreds) that is
        | deliberately modest: a siphon is the SAFE half of a backdoor
        | portfolio and safe income should be slow income. What makes it worth
        | holding is that it arrives whether or not anybody goes anywhere.
        |
        | It is a BASE and expects to move. `siphon_yield` is a resolved stat,
        | so a skill that makes somebody better at draining a machine is a
        | modifier on it and never a branch here — the same shape every other
        | number in the game takes. There are no such skills yet.
        |
        | Nothing has been balanced against it, like every number a backdoor
        | touches. The dial to turn is this one.
        */
        'siphon' => [
            'per_grade' => 30,
        ],

        'ageing' => [
            'base' => 1.0,
            'per_day' => 0.05,
            'cap' => 10.0,
        ],
    ],

    /*
    | HOW LONG A MACHINE STAYS SHUT AFTER IT FINDS OUT.
    |
    | TWO TO SIX HOURS, rolled per lockdown. Long enough that waiting one out
    | is not a plan — a player who tripped an IDS does not sit on the square
    | for ten minutes and go back in — and short enough that a machine is not
    | taken out of the world for a session. The spread is there so a lockdown
    | cannot be timed: coming back at the earliest possible moment is a guess
    | rather than a schedule.
    |
    | What the window IS, mechanically: nobody may start a run on the machine,
    | and when it closes the machine relocates. Anybody already inside when it
    | begins is thrown out — see `RunEnding::Cancelled`, which costs the flat
    | point of Synaptic Integrity a readiness loss costs and nothing else.
    |
    | Expiry is swept every few minutes rather than settled when somebody
    | looks. That is deliberate and it is the one place cyberspace does not use
    | lazy settlement: two machines relocating into the same free position is a
    | race, and the sweep is what serialises it.
    */
    'lockdown' => [
        'hours' => ['min' => 2, 'max' => 6],
    ],

    /*
    | HOW MANY RAIDS A NODE SURVIVES BEFORE SOMEBODY RECONCILES THE NUMBERS.
    |
    | THREE, and the counter is on the SYSTEM rather than on the character —
    | three by anybody, not three by you. The world is shared, so a node is a
    | resource other people are also emptying, and a per-character counter
    | would have every popular machine raided into the ground by a hundred
    | people who each thought they had two left.
    |
    | This is the arithmetic brake on a perfect loop. The detection floor is
    | the other one: no run is ever safer than about 3%, so luck fires the
    | first and counting fires the second, and neither can be played around.
    | A quiet thief gets three good nights out of a system, never an annuity.
    |
    | THE COUNTER RESETS WHEN THE MACHINE RELOCATES, because a relocated
    | machine is a new one — new address, new position, books balanced.
    | Without the reset every system on the grid would be on a permanent
    | countdown to its third raid and the world would grind into pure churn.
    |
    | Raising this makes the low end of the grid a steadier income; lowering it
    | makes finding a machine matter more than farming one. Nothing has been
    | balanced against it, like everything else a raid touches.
    */
    'raids' => [
        'limit' => 3,
    ],

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

    /*
    |--------------------------------------------------------------------------
    | How fast a trace gets through a routing chain
    |--------------------------------------------------------------------------
    |
    | THE CHANCE THAT THE TRACE ADVANCES AT ALL THIS TURN, per cent, decided by
    | the GRADE of each backdoored system it has to come through. A grade 1
    | relay slows it not at all; a grade 9 one stops it four turns in five.
    |
    | A TRACE WALKS THE CHAIN, one system at a time. Each turn is a single
    | attempt at the NEXT hop: pass it and the trace moves up and the turn ends,
    | fail and it stays put and the turn ends anyway. A hop once passed is
    | behind it and never rolled again — which is the difference between a long
    | chain being slow and a long chain being impassable.
    |
    | A HOP ONLY EVER DELAYS BY FAILING. Passing one costs the trace nothing:
    | it moves up and advances in the same turn. So what a chain is worth is
    | the held turns its failures buy — the sum of (1/p - 1) over its hops —
    | and a hop's value is entirely its grade. That is a decision and not an
    | omission: the alternative, where passing a hop also costs a turn, would
    | make ten junk machines worth ten guaranteed turns and put the price of a
    | chain in its LENGTH rather than in what it is made of.
    |
    | GRADE 1 IS 95 AND NOT 100, which is the whole of what keeps a worthless
    | machine worth backdooring. At 100 a low-grade hop was decoration — it
    | could never fail, so it could never delay anything, and the design says a
    | routing backdoor is useful on any system at all. At 95 one junk hop is
    | worth about a twentieth of a turn and a full chain of ten has a 40%
    | chance of buying at least one — a chance rather than an income, which is
    | the right shape for a machine nobody would otherwise bother with.
    |
    | Three grade 9s is about twelve held turns, which is roughly what three
    | successful backdoor runs ought to be worth — and a ten-hop chain of them
    | is the reason losing the lot to one completed trace is a real price.
    |
    | Past the end of the chain nothing is in the way and the trace comes on
    | unopposed. An EMPTY chain is that case from the first turn, which is the
    | only one that exists today.
    |
    | Ten points a grade below the top, so the table is the design rather than
    | a curve somebody fitted. CYBERSPACE.md wondered whether a hop should also
    | read who owns the machine and how well it is dug in: **decided against**.
    | Grade is the differentiator and it is the one already on the row; an
    | owner weight would need a number per entity for 121 of them, and reading
    | age would pull against the ageing roll, which makes an old backdoor more
    | likely to vanish rather than better.
    */
    'trace' => [
        'advance' => [
            1 => 95,
            2 => 90,
            3 => 80,
            4 => 70,
            5 => 60,
            6 => 50,
            7 => 40,
            8 => 30,
            9 => 20,
        ],

        /*
        | What a completed trace puts on the character, PER GRADE of the system
        | that traced them: two at grade 1, eighteen at grade 9. Being followed
        | home by somebody serious is worth more than by a junk box.
        |
        | Heat is persistent and belongs to the person — it is what the world
        | knows about them. What it will DO is random encounters, in cyberspace
        | and on the street, that have to be beaten; until then it only drives
        | the bounty roll. See TODO.md.
        */
        'heat_per_grade' => 2,
    ],

    /*
    | Burning a machine out of existence.
    |
    | THE ONLY COST IS HEAT: the burned system's GRADE × 2.5, against a
    | completed trace's grade × 2. Burning a system must always be worse than
    | being caught hacking it — a trace is the world following somebody home,
    | a burn is a system missing from the grid in the morning. Heat is a
    | decimal to one place, so the half-points land as they are.
    |
    | Deliberately nothing else. A burn takes no loot, counts no raid and
    | leaves no lockdown — there is nothing left to shut — so what it spends is
    | the run itself and everything the machine would ever have been worth
    | again. That opportunity cost is the design's own answer to "what does it
    | cost the runner", and inventing a second price before heat means anything
    | would be balancing against a number nobody has set.
    */
    'burn' => [
        'heat_per_grade' => 2.5,
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
        | A difficulty handed to App\Checks\CheckRoller against `stealth`.
        |
        | EACH GRADE IS A COIN FLIP FOR WHOEVER OUGHT TO BE STANDING THERE:
        |
        |   grade 1    10   an untrained runner (stealth ~9)       48%
        |   grade 5    36   fully trained, average attributes (~35) 46%
        |   grade 9    58   the best reachable build (~61)          59%
        |
        | — the last is the IDS alone; with a grade 9 tracer and ICE also
        | looking it is 52-54% a look, and about a quarter for a whole run,
        | since the core touch rolls again. The steps narrow towards the top
        | because the stealth range does.
        |
        | IT USED TO BE `scan.difficulty`'S TABLE, and ran to 92: the best
        | build anybody can actually reach was seen by a grade 9 IDS nearly
        | every time. The two roll different stats with different ceilings, so
        | they are two curves now. The scan table did not move.
        |
        | STORED ON THE ROW AT CREATION, so changing this moves new machines
        | only — the migration that set these values rewrote the old ones.
        */
        'ids' => [
            1 => 10,
            2 => 17,
            3 => 24,
            4 => 30,
            5 => 36,
            6 => 42,
            7 => 48,
            8 => 53,
            9 => 58,
        ],

        'tracer' => [

            /*
            | WHAT A TRACER NOTICES, and it is LOWER than an IDS at the same
            | grade — a fifth of it at the bottom, widening to about
            | two-thirds at the top.
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

    /*
    |--------------------------------------------------------------------------
    | What heat does
    |--------------------------------------------------------------------------
    |
    | Heat is persistent, it belongs to the PERSON rather than the run, and it
    | is what the world knows about them. A completed trace puts one point on,
    | a burn two, and until now nothing read it back — a number that only goes
    | up and changes nothing is not a mechanic.
    |
    | THE FIRST THING IT DOES IS ATTRACT ATTENTION. Every night, once, each
    | character carrying any heat is rolled against the chance that somebody
    | decides they are worth money. Under `floor_below` it is the uncertainty
    | floor and nothing more; from there it climbs in a straight line to
    | certain at `certain_at`.
    |
    | A STRAIGHT LINE BECAUSE NOTHING IS BALANCED AGAINST IT YET. Anything
    | shapelier would be a curve somebody has to defend before there is a game
    | to defend it against, and `App\Characters\Bounties::chanceFor()` is one
    | method to change when heat is finished.
    |
    | CERTAIN AT THE TOP IS DELIBERATE and does not break the rule that no
    | check is ever certain: this is not a check. Nobody is attempting anything
    | and there is nothing to be good at — it is the world deciding about you,
    | which is the same shape the market's rarity roll and the backdoor ageing
    | roll have, and those reach certainty too.
    |
    | THE SECOND THING IT DOES IS SEND SOMEBODY. `hunt` below is the answer to
    | "what a bounty IS" — a hunter, rolled on every move rather than once a
    | night, because the bounty message is a warning and this is the thing it
    | warned about.
    |
    | The rest of what CYBERSPACE.md asks of heat is still unbuilt: faction
    | standing, and telling a player-owned system's owner who hit them. See
    | TODO.md.
    |
    */
    'heat' => [
        'bounty' => [
            'floor_below' => 5,
            'certain_at' => 100,
            // Nights after word goes out before anybody is rolled again. A
            // memory, not a softer curve: at a hundred heat the answer is still
            // yes, it just is not news every morning. Unbalanced, like the rest.
            'cooldown_days' => 7,
            // What each hit ADDS to the price on a head: heat × per_heat, times
            // (1 + per_grade × (grade − 1)) for the highest grade that cost them
            // heat since the last hit. Heat 20 at grade 1 is 1000; at grade 9, 3000.
            // Unbalanced.
            'amount' => [
                'per_heat' => 50,
                'per_grade' => 0.25,
            ],
        ],

        /*
        | A HUNTER, ROLLED ON EVERY MOVE — `App\Cyberspace\Navigator::arrive()`,
        | the one place all four ways of moving already meet. Deliberately NOT
        | the bounty curve: that one reaches certainty by design, checked once a
        | night, and a chance that reaches 100% checked on every step would be a
        | hunter on your very next move. This has its own floor and its own CAP,
        | never certain, because you keep moving through a hot patch rather than
        | being walled by it.
        |
        | THE CURVE IS A STRAIGHT LINE, the same call the bounty roll makes and
        | for the same reason: nothing is balanced against this yet.
        |
        |   heat   5   ->  0.5%   the same floor logic as the bounty roll
        |   heat 100   -> 35.0%   and it stops climbing there
        |
        | ROLLED THE SAME WAY AS EVERYTHING ELSE THAT IS THE WORLD DECIDING
        | ABOUT YOU rather than a check: an injected die, in thousandths, so a
        | chance of nothing never fires and a chance of everything always does.
        */
        'hunt' => [
            'floor_below' => 5,
            'chance_at_floor' => 0.5,
            'certain_at' => 100,
            'cap' => 35.0,

            /*
            | FIVE GRADES, each a heat band and the ICE LEVELS it draws from —
            | not new numbers, the real catalogue. A hunter IS an ICE, on the
            | fly, which is what makes Ensnare and every existing resistance
            | (Reactive Firewalling, Defensive Compartmentalization, Spectral)
            | reach it for free: the damage goes through the ordinary
            | `damage.synaptic_integrity.ice` stat, because it is that damage.
            |
            | GRADE 5 IS DELIBERATELY THE SAME RANGE A GRADE 8-9 SYSTEM DRAWS
            | FROM (levels 10-12) — "comparable to a grade 8 or 9 system" was
            | the design brief, and reusing the range makes that exactly true
            | rather than approximately true.
            |
            | The top band's heat range is open-ended: heat has no ceiling, and
            | grade 5 is simply "as hard as this gets" from 81 up.
            */
            'grades' => [
                1 => ['heat' => [5, 24], 'ice_levels' => [1, 2], 'creds' => [250, 350]],
                2 => ['heat' => [24, 43], 'ice_levels' => [3, 4], 'creds' => [400, 550]],
                3 => ['heat' => [43, 62], 'ice_levels' => [5, 7], 'creds' => [550, 700]],
                4 => ['heat' => [62, 81], 'ice_levels' => [8, 9], 'creds' => [700, 850]],
                5 => ['heat' => [81, null], 'ice_levels' => [10, 12], 'creds' => [850, 1000]],
            ],

            // Winning is the meaningful way to manage heat: a flat amount per
            // grade, not a percentage, so it does not shrink to nothing at low
            // heat the way a percentage would. 5/10/15/20/25 by grade.
            'win_heat_reduction_per_grade' => 5,

            // Losing still costs SOMETHING (see App\Cyberspace\HunterCombat),
            // but is deliberately cheaper and less predictable than winning —
            // a random slice of what you were carrying, not a fixed amount and
            // never the whole of it.
            'defeat_heat_loss' => [0.05, 0.15],

            // A LOW CHANCE of a program on top of the creds. Drawn from
            // whatever is on the market today rather than a dedicated loot
            // table — a simpler first pass, worth a proper table once hunters
            // have been played.
            //
            // NO COOLDOWN, unlike the bounty roll. A hunter just beaten is
            // exactly as likely to come again on your next step as the curve
            // says — staying hot is the risk, and nothing here softens it.
            // `Bounties::chanceFor()`'s cooldown is the pattern to copy if
            // chaining turns out to feel wrong once this is played.
            'program_chance' => 8,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Speaking the machine's operating system
    |--------------------------------------------------------------------------
    |
    | THE LAST THING BETWEEN A RUNNER AND THE CORE. Fighting the ICE is one
    | program against another and needs no vocabulary; telling the machine
    | itself to hand something over, open a door or destroy itself is done in
    | the operating system it runs, and `systems.os_skill_id` names the skill
    | that is.
    |
    | IT IS A ROLL RATHER THAN A GATE, which is the whole of this mechanic. The
    | check value is read off `values` below by the runner's LEVEL in that
    | skill, against a flat `difficulty`, through the ordinary logistic — so
    | the curve runs 4.7% / 18% / 48% / 77% / 89% / 99.15%.
    |
    | Those are the numbers the design asked for at both ends: level 5 is "more
    | or less 100%, using the uncertainty floor", and no skill at all is "nigh
    | on impossible" — not because a wall says no, but because 4.7% a turn
    | against a trace that is walking towards you is a losing race.
    |
    | THERE IS NO GRADE TERM, deliberately. A machine's grade already decides
    | WHICH operating system it runs — the skill's rank follows the grade — so
    | a grade 9 box is hard to speak to by running something nobody has
    | trained, not by being the same language spoken faster. Putting grade in
    | here as well would charge for it twice.
    |
    | A MACHINE RUNNING NOTHING IS OPEN TO ANYBODY. That is the portal's case
    | and cannot be reached from a run anyway.
    |
    */
    'os' => [

        /*
        | THE CHECK VALUE PER LEVEL, and the curve it makes against the
        | difficulty below at the default spread of 8:
        |
        |   level 0     0.0    4.7%     never trained it, and it shows
        |   level 1    11.9     18%     you have seen one before
        |   level 2    23.4     48%     near enough a coin flip
        |   level 3    33.7     77%
        |   level 4    40.7     89%
        |   level 5    65.0   99.15%    the certainty floor answers, not this number
        |
        | A TABLE RATHER THAN A MULTIPLIER, because the curve is hand-shaped
        | and a straight line cannot draw it: the step from 0 to 1 is worth
        | nearly twice the step from 3 to 4, which is the shape of learning
        | something rather than the shape of a formula. Each value is simply
        | what the logistic needs to land on the chance beside it.
        |
        | LEVEL 5 IS PAST THE CLAMP ON PURPOSE. Anything above about 62 is
        | already at `checks.certainty_floor` from the other side, so the
        | number is chosen to be comfortably there — the floor is what answers
        | at the top of the curve, not the table, and it keeps answering if the
        | spread is ever retuned.
        |
        | The chances move if `checks.spread` moves, which is correct: that
        | number is how decisive the whole game is, and an OS curve that held
        | still while everything else loosened would be opting out of it.
        |
        | DECIMAL, because each is the BASE of the skill's `check.<slug>` stat
        | and that stat no longer rounds down (`App\Stats\SkillStats`) — a
        | check was never really a whole number, and truncating it here bought
        | nothing but a slightly-off curve. Anything else that helps with an
        | operating system is a modifier on that same check.
        */
        'values' => [
            0 => 0.0,
            1 => 11.9,
            2 => 23.4,
            3 => 33.7,
            4 => 40.7,
            5 => 65.0,
        ],

        'difficulty' => 24,
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

        /*
        | HOW LONG ONE PASS TAKES, in minutes, for somebody with no skill at
        | all. A grade 1 box gives itself up in about a minute; a grade 9 takes
        | the better part of a quarter of an hour, and a read goes round until
        | it has the picture or the file goes stale.
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
        | is content, it has an imprint and a tree position, and a game that decided
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
    /*
    | HOW LONG A DEFEAT KEEPS YOU DOWN, in seconds, before modifiers.
    |
    | Sixty. The base of the `recovery_seconds` stat, so implants and skills can
    | cut it the way they cut everything else. SECONDS rather than minutes
    | because the stat rounds UP: on a base of one minute, any percentage short
    | of −100% rounded straight back to the whole minute and did nothing.
    |
    | Short on purpose: the wait is not the punishment. What losing actually
    | costs is whatever the defeat tags carried — a piece of a ceiling, a
    | destroyed module, a wiped deck — and the minute is only there so that a
    | defeat carrying NOTHING still costs something. A long wait would punish
    | the player who lost to a harmless guard hardest, which is backwards.
    */
    'recovery_seconds' => 60,

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
    | not texture any more: 121 entities by three types is 363 combinations, so
    | without a serial a name identifies about eight machines at once.
    |
    | Rolled rather than counted. A counter would let a player read how many
    | machines an entity runs off the side of one of them.
    */
    'designation' => ['min' => 1, 'max' => 999],

];
