<?php

declare(strict_types=1);

use App\Models\Character;

/*
|--------------------------------------------------------------------------
| Stat registry
|--------------------------------------------------------------------------
|
| Every number the modifier system can touch is declared here exactly once.
| See docs/MECHANICS.md for the rules; the short version:
|
|   tags      what a modifier can target as a group. Prefer targeting tags
|             over listing ids, so a new attribute is picked up automatically.
|   min/max   the clamp, applied ONCE at the end of resolution. null = unbounded.
|   rounding  round | floor | ceil | none. Applied once, after the clamp.
|
| Ids are permanent: they end up in stored modifier definitions. Retire a stat,
| never rename or renumber one.
|
| Player-visible names and descriptions live in lang/<locale>/character.php
| under names.* and descriptions.*, not here.
|
*/

$attributes = [];

foreach (Character::ATTRIBUTES as $attribute) {
    $attributes[$attribute] = [
        'tags' => [
            'attribute',
            in_array($attribute, Character::PHYSICAL_ATTRIBUTES, true) ? 'physical' : 'mental',
        ],
        // The lifetime bound, not the creation one. Creation is capped tighter
        // by validation; a resolved value may legitimately reach 100 in play.
        'min' => Character::ATTRIBUTE_MIN,
        'max' => Character::ATTRIBUTE_MAX,
        'rounding' => 'round',
    ];
}

return [

    'stats' => $attributes + [

        /*
         * Derived. Its BASE is computed by Character::baseStat() rather than by
         * a formula declared here, because the derived-stat dependency graph is
         * still specified-only (docs/MECHANICS.md, step 4). Declaring it as a stat
         * is enough for modifiers to target it today.
         *
         * The floor of 1 answers docs/MECHANICS.md open question 2 provisionally: a
         * debuff must never drive max HP to 0 and kill a character as a side
         * effect of losing an implant. Only damage reaches 0.
         */
        'hitpoints_max' => [
            'tags' => ['derived', 'vital'],
            'min' => 1,
            'max' => null,
            'rounding' => 'hundredth',
        ],

        /*
         * The _max suffix is a naming rule, not decoration: these are DERIVED
         * ceilings, computed and never stored. The matching current values
         * (hitpoints_current, synaptic_integrity_current) are RESOURCES —
         * stored state, clamped down by these. Neither resource exists yet.
         * See docs/MECHANICS.md, "Derived stats vs resources".
         */
        /*
         * A DECK'S MEASURES.
         *
         * One per column on `deck_modules`, because MECHANICS says every one
         * of them is the base of a resolved stat rather than a number to read
         * off the row: implants, skills, drugs and injuries are all expected
         * to move them, and none of that may end up as a special case living
         * in deck code.
         *
         * The BASE is not a formula over attributes — it is the sum of what is
         * fitted, which App\Decks\DeckTotals computes. That sum is the
         * resolver's INPUT and never a second implementation of it, so these
         * stats deliberately have no entry under `formulas` below and
         * Character::baseStat() is not the way in.
         *
         * A floor of 0 on all five: a debuff may take a measure to nothing but
         * never below it, and negative compute is not a state the game has.
         * Carried to one decimal place (see App\Support\Qb): a
         * lightweight script costs a fraction of a unit.
         */
        'deck_qflops' => [
            'tags' => ['deck', 'compute'],
            'min' => 0,
            'max' => null,
            'rounding' => 'tenth',
        ],

        'deck_quantabits' => [
            'tags' => ['deck', 'memory'],
            'min' => 0,
            'max' => null,
            'rounding' => 'tenth',
        ],

        /*
         * Bulk storage. Quantabits like deck_quantabits and deliberately NOT
         * the same stat: memory is what a program occupies while it runs,
         * storage is what a file costs by existing. DeckTotals sums by measure,
         * so one id for both would merge two different capacities into one
         * meaningless figure — the same reason draw and supply are two stats
         * that share a unit.
         */
        /*
        | How long one pass takes, IN SECONDS.
        |
        | Seconds rather than minutes because a modifier that takes 40% off
        | four minutes should give two minutes twenty-four, and a stat that
        | rounds to whole minutes would give two. The config that feeds it is
        | in minutes, which is what a person tuning it wants to read.
        |
        | Floored at ONE SECOND rather than zero: an instrument good enough to
        | read a machine instantly is a mechanic that has stopped being one,
        | and a floor is cheaper than finding that out from a player.
        */
        /*
        | How many instances of ONE program a character may run at once.
        |
        | The base is `programs.threads`, which is 1: a new character runs a
        | single copy of anything. Multithreading raises it, and so could an
        | implant or a deck one day — it is a stat rather than a level lookup
        | for exactly that reason.
        |
        | A ceiling per program, never a total: two threads means two scanners
        | or two decryptors, not two processes. Floored at 1, because a
        | character who could run none of something would be one for whom every
        | program in the game had quietly stopped existing.
        */
        'program_threads' => [
            'tags' => ['software'],
            'min' => 1,
            'max' => null,
            'rounding' => 'floor',
        ],

        /*
        | How many OTHER runners this character may take into a system.
        |
        | The base is `cyberspace.coop.slots`, which is ZERO, and that is the
        | difference from `program_threads` above rather than an oversight.
        | Software floors at one because a character who could run no copy of
        | anything is a character for whom every program in the game quietly
        | stopped existing. A character who can call no cooperative run has
        | simply not trained for it — **zero is the ordinary state and it is
        | also the gate**, the same way no icebreaker means no fighting ICE.
        |
        | Raised a slot per level by Coordinated Strike and again by Advanced
        | Coordination, so both trained out is ten others and eleven runners in
        | one machine. It is the RUN OWNER'S stat and nobody else's: whoever
        | calls the run is who trained for it, and a participant needs none of
        | it. That is what keeps it a specialisation worth a rank 8 and a rank
        | 16 slot instead of a level everybody buys one of.
        |
        | NOTHING READS IT YET. Cooperative runs are phase 7; this exists so
        | the two skills can be authored in the editor, which refuses a
        | modifier whose target reaches nothing.
        */
        /*
        | How long somebody stays on the floor, in seconds.
        |
        | ONE MINUTE, and it is the floor under every defeat: whatever the
        | program that beat you carried, and whether it carried anything at all,
        | the wait is what losing always costs. That is what stops a defeat with
        | an empty tag list from being free.
        |
        | A stat rather than a constant because implants and skills are meant to
        | cut it — waking up faster is exactly the sort of thing a wired nervous
        | system should buy. Floored at ZERO on purpose: a build good enough to
        | get straight back up has earned it, and nothing breaks if the wait is
        | over before it began.
        */
        'recovery_seconds' => [
            'tags' => ['derived', 'vital'],
            'min' => 0,
            'max' => null,
            'rounding' => 'ceil',
        ],

        /*
        | Synaptic Integrity recovered per minute, on its own.
        |
        | ZERO BY DEFAULT: the fast trickle is paid for. Everybody mends a point
        | every two hours (`synaptic_mend_minutes`, below); anything quicker is
        | bought — Neuroplasticity, a point a minute per level, or a dose.
        |
        | Per MINUTE rather than per tick, so whatever eventually credits it can
        | do so lazily off elapsed time the way a training deposit does. Nothing
        | credits it yet.
        */
        'synaptic_regen' => [
            'tags' => ['derived', 'vital'],
            'min' => 0,
            'max' => null,
            'rounding' => 'hundredth',
        ],

        /*
        | Hit points recovered per HOUR, on its own.
        |
        | ZERO BY DEFAULT and nothing heals hit points on its own without it: clinics and medkits are the way back
        | (docs/COMBAT.md). An effect row carries it (a dose, an implant, a skill, a piece of armour), as `synaptic_regen`
        | is carried.
        |
        | PER HOUR AND NOT PER MINUTE, because the smallest whole rate is the smallest thing anybody can grant, and a
        | point a minute is sixty an hour: more than a whole character's hit points, from the weakest effect there
        | could be. An hour makes the smallest grant one point an hour, and `characters:regenerate` spreads it evenly
        | across the hour (an exact share of a point each minute, carried by the clock and not by stored state), so
        | there is no lump on the hour and no remainder to keep.
        */
        'hitpoints_regen' => [
            'tags' => ['derived', 'vital'],
            'min' => 0,
            'max' => null,
            'rounding' => 'hundredth',
        ],

        /*
        | Minutes between points of Synaptic Integrity that come back ON THEIR
        | OWN, for everybody: the slow floor under healing, a point every two
        | hours. `synaptic_regen` above is the fast trickle somebody pays for.
        |
        | An INTERVAL rather than a rate, because a rate of 1/240 a minute
        | floors to nothing — the trap `recovery_minutes` fell into. So an
        | implant that heals a quarter faster is −25% here, and lands.
        | Rounded up and at least one minute, so nothing divides by zero.
        */
        'synaptic_mend_minutes' => [
            'tags' => ['derived', 'vital'],
            'min' => 1,
            'max' => null,
            'rounding' => 'ceil',
        ],

        /*
        | Seconds in one decryption cycle: how long a Decryptor works before the single check that may open the file.
        |
        | An INTERVAL for the reason `synaptic_mend_minutes` is, so a percentage off it lands (-2% a level is six seconds
        | off a five-minute cycle) and a faster cycle is more checks, not a better one. The CHANCE of each check is
        | `check.<slug>` of the file's encryption, scaled by `decryption_chance.<slug>`; this is only how often it is
        | rolled. Seconds so a small modifier is a whole number of them. Rounded up, at least a second.
        */
        'decryption_cycle_seconds' => [
            'tags' => ['derived'],
            'min' => 1,
            'max' => null,
            'rounding' => 'ceil',
        ],

        /*
        | CRAFTING (docs/CRAFTING.md). How many things a character can be building at once; one until a skill says otherwise.
        | Whole, at least one.
        */
        'crafting_slots' => [
            'tags' => ['derived'],
            'min' => 1,
            'max' => null,
            'rounding' => 'floor',
        ],

        /*
        | What a recipe's component quantities are scaled by, as a percent: 100 is the recipe as written, and a skill that
        | shaves a few percent off leaves it at 98. Each line is rounded up and is never below one. Never under 10.
        */
        'crafting_cost' => [
            'tags' => ['derived'],
            'min' => 10,
            'max' => null,
            'rounding' => 'none',
        ],

        /*
        | How hard this character is to notice in somebody else's machine.
        |
        | WHAT AN IDS IS ROLLED AGAINST. A machine's detection value is the
        | difficulty and this is the value, so the margin between them is the
        | whole of whether a run is a quiet one — see docs/CYBERSPACE.md,
        | *Detection*.
        |
        | ZERO BY DEFAULT, and that is the same call `icebreaker_damage` makes:
        | somebody who has not trained for this is seen, every time, by
        | everything. Sneaking is a thing you buy rather than a thing you have,
        | which is what makes an unnoticed run worth the investment it costs.
        |
        | Raised flat by Obfuscation and as a percentage by Clandestine
        | Intrusion Methods — and percentages apply to base PLUS flat and are
        | summed before they are applied once, so the second skill is worth
        | nothing without the first. That is why it requires it.
        |
        | Evasion programs are the other channel and none exist yet:
        | `ProgramKind::Evasion` has been an empty case since programs shipped,
        | and this is the stat it was waiting for.
        */
        'stealth' => [
            'tags' => ['cyberspace'],
            'min' => 0,
            'max' => null,
            // A FRACTION, kept to one decimal. The whole range is single
            // digits before training, so rounding to a whole number would
            // throw away most of what the first few levels buy.
            'rounding' => 'tenth',
        ],

        /*
        | How hard a running icebreaker cuts, per turn.
        |
        | The base is ZERO and that is the gate: no icebreaker running means no
        | damage, and no amount of skill adds up to having one. An icebreaker's
        | own bite is a modifier on its `programs.modifiers` like everything
        | else a program is worth — so a skill that sharpens one and a better
        | one are the same kind of thing to the resolver, and neither is a
        | branch in the simulator.
        |
        | Every icebreaker is deliberately too slow on its own: each does
        | strictly less than would break its matching ICE in twenty turns, and
        | the whole of that gap is what skills are meant to close. A bare
        | character therefore cannot win a fight at all — see the migration
        | that authored them.
        |
        | NOTHING READS IT YET. The fight is the simulator.
        */
        'icebreaker_damage' => [
            'tags' => ['software'],
            'min' => 0,
            'max' => null,
            'rounding' => 'hundredth',
        ],

        /*
        | How good somebody is at leaving a machine that does not want them to.
        |
        | A POOL, like stealth, and for the same reason: leaving is a
        | capability rather than something you spend, and a capability reads
        | like an average. Rolled against the machine's grade plus whatever is
        | currently fighting you — see `config/cyberspace.egress`.
        |
        | It has no floor of zero the way stealth and `icebreaker_damage` do,
        | because there is no gate here to be behind. Everybody can always try
        | to leave; a run with no exit is a character deleted by a door they
        | could not see. What a bad pool buys is a poor chance and more turns
        | spent buying it.
        |
        | EGRESS PROTOCOLS IS THE FIRST THING THAT MOVES IT — +3% of base per
        | level, five grants on one skill row, which is what docs/CYBERSPACE.md
        | always said the shape would be: a skill that helps you leave is a
        | modifier on this id and never a line in the simulator. Nothing in
        | `Simulator` knows it exists.
        */
        'egress' => [
            'tags' => ['cyberspace'],
            'min' => 0,
            'max' => null,
            // A FRACTION, kept to a tenth, exactly as stealth is: the whole
            // useful range is single digits, so whole numbers would throw away
            // most of what an attribute point is worth.
            'rounding' => 'tenth',
        ],

        /*
        | How many systems a character may route a run through.
        |
        | THE CEILING IS TEN AND IT IS ALL SKILL: one hop per level of Routing
        | and one more per level of Advanced Routing, which is the table
        | docs/CYBERSPACE.md has carried since the chain was specified. Implants and
        | gear are expected to lift it later, and they will do so by being
        | modifiers on this id rather than by anything learning what a chain is.
        |
        | BASE ZERO, so it is a gate before it is a number — the same shape as
        | `icebreaker_damage` and `stealth`. A character who has trained
        | neither skill routes through nothing and a trace comes straight at
        | them, which is the ordinary state rather than a penalty.
        |
        | It is a CEILING and not an allowance: a run declares its own chain
        | from the routing backdoors its owner holds, and this is the most it
        | may name. Holding fewer is the usual case.
        */
        /*
        | What one siphon backdoor pays its holder in a night.
        |
        | THE BASE IS THE MACHINE'S and the modifiers are the PERSON'S: the
        | caller hands in (3000 x grade) / 100 and the resolver applies
        | whatever its owner carries, so a skill that makes somebody better at
        | draining a machine raises every siphon they hold at once. There are
        | no such skills yet; this is the id the first one will target.
        |
        | Deliberately NOT in `stats.bases`, unlike `routing_hops`: there is no
        | such thing as a character's siphon yield, only this machine's. Asking
        | `Character::stat()` for it would be asking a question with no answer,
        | and it throwing is the right outcome.
        */
        'siphon_yield' => [
            'tags' => ['cyberspace'],
            'min' => 0,
            'max' => null,
            'rounding' => 'floor',
        ],

        /*
        | HOW MUCH HEAT COMES OFF A NIGHT, in points.
        |
        | Doing nothing is a valid way to cool off and a slow one: half a point
        | a night, so a completed trace is two nights to shake and a burn is
        | four. There is no ceiling on heat, so there is no ratio here — this
        | is an absolute, like the thing it reduces.
        |
        | A STAT RATHER THAN A CONSTANT because this is where the skills go.
        | Nothing raises it today — no skill in the catalogue names it — but a
        | fixer's contacts, a clean identity or a scrubbed record are all the
        | same shape: a modifier on this id, and `characters:cool` learns
        | nothing new when one arrives.
        |
        | Floored at 0 and rounded to a TENTH, which is the precision
        | `characters.heat` stores and the same the pools keep. A modifier that
        | took cooling below nothing would be heat that grows while you sit
        | still, which is a different mechanic and not this one's to invent.
        */
        'heat_decay' => [
            'tags' => ['heat'],
            'min' => 0,
            'max' => null,
            'rounding' => 'tenth',
        ],

        /*
        | WHAT A JOB IS WORTH IN STANDING, before the entity rounds it.
        |
        | The base is not here: it arrives per mission, snapshotted onto the
        | row when the job was offered, because the curve is per agent GRADE
        | and lives in `config/missions.php`. This entry is what lets anything
        | MOVE it — the first perk, implant or skill that makes somebody better
        | at being liked is a modifier on this id, and `MissionLedger` never
        | learns such a thing exists.
        |
        | ROUNDING IS `none`, which is the only honest answer here: a grade 1
        | job is worth hundredths of a point, and the coarsest mode the stat
        | graph offers is a tenth — which would round the whole bottom of the
        | ladder to zero. `StandingLedger::record()` rounds to the column's
        | three decimals before it writes anything, so the precision is kept
        | exactly where the column is.
        |
        | Floored at 0. A modifier that took a REWARD negative would be a job
        | that costs standing to finish, which is a different mechanic and not
        | this one's to invent — failing already has its own number, and that
        | one is deliberately flat.
        */
        'standing_gain' => [
            'tags' => ['standing'],
            'min' => 0,
            'max' => null,
            'rounding' => 'none',
        ],

        'routing_hops' => [
            'tags' => ['cyberspace'],
            'min' => 0,
            'max' => null,
            'rounding' => 'floor',
        ],

        // How far a character sees on the grid: a LEVEL from 0 to 9, each one a shape (cyberspace.view_shapes).
        // Its base is cyberspace.view_range; a skill and a script or two add whole levels, so rounding is rarely
        // asked, and rounds half up when it is.
        'view_range' => [
            'tags' => ['cyberspace'],
            'min' => 0,
            'max' => 9,
            'rounding' => 'round',
        ],

        'coop_slots' => [
            'tags' => ['cyberspace'],
            'min' => 0,
            'max' => null,
            'rounding' => 'floor',
        ],

        /*
        | The TRACE'S chance, in per cent, of getting past one hop of the
        | routing chain. The base is the hop's grade off
        | `cyberspace.trace.advance` (95 at grade 1 down to 20 at grade 9),
        | handed in where the hop is rolled — deliberately not in
        | `stats.bases`, because there is no such thing as a character's hop
        | chance apart from a hop. It is the trace's number, so a skill that
        | makes a chain hold better is NEGATIVE: −10% of base is a grade 1 hop
        | at 85.5.
        */
        'trace_hop_chance' => [
            'tags' => ['cyberspace', 'trace'],
            'min' => 0,
            'max' => 100,
            'rounding' => 'none',
        ],

        /*
        | ENSNARE'S CUT to the egress chance, in per cent, before it is turned
        | into a multiplier. The base is the active ICE's own reduction off
        | `cyberspace.attack_tags.ensnare` (25/40/55 by level), handed in where
        | the multiplier is built — deliberately not in `stats.bases`, for the
        | same reason `trace_hop_chance` is not there: there is no such thing
        | as a character's ensnare reduction apart from a specific fight.
        |
        | NEGATIVE HELPS, exactly as `trace_hop_chance` reads: this is what
        | Ensnare takes FROM you, so a skill that makes it bite less is
        | `percentage_of_base` with a negative amount. Floored at 0 — Ensnare
        | can be blunted to nothing, never turned into a bonus.
        */
        'ensnare_reduction' => [
            'tags' => ['cyberspace', 'ensnare'],
            'min' => 0,
            'max' => null,
            'rounding' => 'none',
        ],

        /*
        | How good somebody is at finding places in the City: a POOL, read
        | through the Sheet like stealth and egress (`Character::stat()` — outside
        | a Sheet a pool reads as zero). See `pools` below.
        */
        'exploration' => [
            'tags' => ['city'],
            'min' => 0,
            'max' => null,
            'rounding' => 'tenth',
        ],

        // Seconds. The base is config/city.php and is handed in at the call
        // site, as scan_duration's is: travel's varies with the district.
        'explore_duration' => [
            'tags' => ['city', 'explore'],
            'min' => 1,
            'max' => null,
            'rounding' => 'floor',
        ],

        // The odds a matured pass opens an encounter, 0-1. The base is
        // config/city.php and is handed in at the call site, as explore_duration's is.
        'encounter_chance' => [
            'tags' => ['city', 'encounter'],
            'min' => 0,
            'max' => 1,
            'rounding' => 'none',
        ],

        /*
        | How many Data Fortresses a character may hold. The base is `cyberspace.fortress.cap` (zero); a skill raises it.
        */
        'fortress_slots' => [
            'tags' => ['cyberspace'],
            'min' => 0,
            'max' => null,
            'rounding' => 'floor',
        ],

        /*
        | A founding Kernel's own numbers, resolved from ITS `items.modifiers` when it is spent and kept on the fortress
        | (`systems.kernel_*`). Bases are `cyberspace.fortress.kernel_integrity` and `kernel_resistance`; demand is a
        | percentage of the config demand, base 100, so +3% is 103. Nothing else reads them.
        */
        'kernel_integrity' => [
            'tags' => ['fortress'],
            'min' => 1,
            'max' => null,
            'rounding' => 'round',
        ],
        'kernel_resistance' => [
            'tags' => ['fortress'],
            'min' => 0,
            'max' => null,
            'rounding' => 'round',
        ],
        /*
        | The per cent chance each night that one of this character's Leechers is found and removed. The base is the host
        | grade's own (`cyberspace.fortress.leech_discovery`), handed in at the call site; read to two decimals, because a
        | skill takes only a small share of it.
        */
        'leech_discovery' => [
            'tags' => ['fortress'],
            'min' => 0,
            'max' => 100,
            'rounding' => 'hundredth',
        ],
        /*
        | How many ticks a Virus infection lasts. The base is the tag's own length (`virus_turns`), handed in at the call site;
        | an Antiviral (a program lowering this with `percentage_of_base`) shortens it, and it is read on every tick.
        */
        'virus_lifetime' => [
            'tags' => ['virus'],
            'min' => 1,
            'max' => null,
            'rounding' => 'round',
        ],
        'kernel_demand' => [
            'tags' => ['fortress'],
            'min' => 1,
            'max' => null,
            'rounding' => 'round',
        ],

        /*
        | The minutes in one cycle of a Decryptor this character installed, and in one run of an Assembler. The base is the item's
        | own (a Decryptor's cycle, an Assembler's Design time less its reduction), so each skill that gates a kind lowers its own,
        | and a modifier on the `production_cycle` tag reaches both. Boosters running on the fortress shorten them further.
        */
        'production_cycle_minutes.decryptor' => [
            'tags' => ['crafting', 'production_cycle'],
            'min' => 1,
            'max' => null,
            'rounding' => 'round',
        ],
        'production_cycle_minutes.assembler' => [
            'tags' => ['crafting', 'production_cycle'],
            'min' => 1,
            'max' => null,
            'rounding' => 'round',
        ],
        // The minutes in one cycle of a Network Tap and of a Transactional Wiretap this character installed.
        'production_cycle_minutes.tap' => [
            'tags' => ['crafting', 'production_cycle'],
            'min' => 1,
            'max' => null,
            'rounding' => 'round',
        ],
        'production_cycle_minutes.wiretap' => [
            'tags' => ['crafting', 'production_cycle'],
            'min' => 1,
            'max' => null,
            'rounding' => 'round',
        ],
        // Per cent on the credits a Transactional Wiretap this character installed skims (relative, summed then applied once).
        'wiretap_yield' => [
            'tags' => ['fortress'],
            'min' => 0,
            'max' => null,
            'rounding' => 'round',
        ],

        /*
        | What a maintenance pass by this character does to a fortress program: raise its values by this per cent, and cut
        | its compute, RAM and storage demand by this per cent (each capped in config). Zero until a skill grants it.
        */
        'maintenance_boost' => [
            'tags' => ['crafting'],
            'min' => 0,
            'max' => null,
            'rounding' => 'round',
        ],
        'maintenance_efficiency' => [
            'tags' => ['crafting'],
            'min' => 0,
            'max' => null,
            'rounding' => 'round',
        ],

        /*
        | How well a maker's fortress ICE and IDS come out, as a percent over their base. Read when a defence is
        | crafted and then frozen onto that copy. Zero for everybody until a skill grants it.
        */
        'defence_quality' => [
            'tags' => ['crafting'],
            'min' => 0,
            'max' => null,
            'rounding' => 'round',
        ],

        /*
        | What an attack rolls against (docs/COMBAT.md, *Defence*). There is no dodge, by decision: a declared stat with a
        | flat base in `bases`, so skills, implants and drugs reach it as modifiers. `Character::stat('defence')`.
        */
        'defence' => [
            'tags' => ['combat'],
            'min' => 0,
            'max' => null,
            'rounding' => 'tenth',
        ],

        /*
        | How hard somebody is to get away from, the other half of a flight roll (`defence` against it).
        | Like `defence`: a declared stat with a flat base, so modifiers can reach it.
        */
        'pursuit' => [
            'tags' => ['combat'],
            'min' => 0,
            'max' => null,
            'rounding' => 'tenth',
        ],

        /*
        | THE CHANCE A PLAYER'S HIT IS CRITICAL, in percent (2 is two in a hundred). Rolled once for every hit that
        | actually lands, and the hit then does `combat.critical.multiplier` times the damage. One stat per realm:
        | physical combat (an attack that is not a near miss) and cyberspace (an icebreaker cutting at ICE or a hunter).
        | A flat base in `bases`, so skills, scripts, implants and drugs reach it as modifiers. Only the player's side
        | rolls it. `Character::stat('critical_chance_physical')`.
        */
        'critical_chance_physical' => [
            'tags' => ['combat'],
            'min' => 0,
            'max' => 100,
            'rounding' => 'tenth',
        ],

        /*
        | How well somebody takes a person to the ground at Close: (muscle + motor) / 3 against the target's `defence`.
        | A pool (`pools.takedown`) so skills, implants and drugs can add to it. docs/COMBAT.md, *Headbutt and takedown*.
        */
        'takedown' => [
            'tags' => ['combat'],
            'min' => 0,
            'max' => null,
            'rounding' => 'tenth',
        ],

        /*
        | Hit points each level of bleeding costs its owner every turn. A flat base in `bases`: an implant, a drug or a
        | skill that clots a wound is a modifier on it, which is how bleeding is reduced GRADUALLY (a consumable stops it).
        */
        'bleed_per_level' => [
            'tags' => ['combat'],
            'min' => 0,
            'max' => null,
            'rounding' => 'hundredth',
        ],

        'critical_chance_cyberspace' => [
            'tags' => ['cyberspace'],
            'min' => 0,
            'max' => 100,
            'rounding' => 'tenth',
        ],

        'surgery_duration' => [
            'tags' => ['city', 'surgery'],
            'min' => 1,
            'max' => null,
            'rounding' => 'floor',
        ],

        'travel_duration' => [
            'tags' => ['city', 'travel'],
            'min' => 1,
            'max' => null,
            'rounding' => 'floor',
        ],

        'scan_duration' => [
            'tags' => ['scan'],
            'min' => 1,
            'max' => null,
            'rounding' => 'floor',
        ],

        /*
        | How much better the programs of one FAMILY are, in per cent: a running program whose `programs.family` is
        | `compressor` has every modifier it carries scaled by 1 + this/100. The base is zero (as written); a skill
        | grants it ("Utility: Compressors +3% / level"). Read by `App\Stats\Sources\RunningPrograms`.
        */
        'family.compressor' => [
            'tags' => ['software', 'family'],
            'min' => 0,
            'max' => null,
            'rounding' => 'tenth',
        ],

        'deck_storage' => [
            'tags' => ['deck', 'storage'],
            'min' => 0,
            'max' => null,
            'rounding' => 'tenth',
        ],

        'deck_bandwidth' => [
            'tags' => ['deck', 'link'],
            'min' => 0,
            'max' => null,
            'rounding' => 'floor',
        ],

        /*
         * The two halves of the power budget. Both are stats, because a skill
         * that CUTS draw is how MECHANICS says an over-committed deck is meant
         * to be solved — so draw has to be movable, not merely summed.
         */
        'deck_power_draw' => [
            'tags' => ['deck', 'power'],
            'min' => 0,
            'max' => null,
            'rounding' => 'floor',
        ],

        'deck_power_supply' => [
            'tags' => ['deck', 'power'],
            'min' => 0,
            'max' => null,
            'rounding' => 'floor',
        ],

        /*
         * THERMAL LOAD. What the deck makes per turn while it is running, and
         * what is taken back out of it. There is no third number for what a
         * deck can stand: a deck stands any amount, and what the load does is
         * done to the modules — each against its own thresholds, which are
         * stats of their own below.
         *
         * NOT called heat: heat is what the world knows about a character, and
         * a completed trace is what puts it there. One word for a wanted level
         * and a temperature is one word too few.
         *
         * These are RATES. A deck at rest makes nothing; a running deck adds
         * this much every turn and the radiators take `deck_cooling` back out,
         * and it is the ACCUMULATED total that the tolerances below are tested
         * against. The run simulator owns that total — it is per run, not per
         * deck, and it resets when the run ends.
         *
         * Both are stats for the reason the others are: a skill that runs a
         * deck cool is exactly the kind of thing this system exists for.
         */
        'deck_thermal_load' => [
            'tags' => ['deck', 'thermal'],
            'min' => 0,
            'max' => null,
            'rounding' => 'floor',
        ],

        'deck_cooling' => [
            'tags' => ['deck', 'thermal'],
            'min' => 0,
            'max' => null,
            'rounding' => 'floor',
        ],

        /*
         * A MODULE'S TOLERANCE. Not a deck total — these do not sum, because a
         * deck's tolerance is its weakest part rather than the sum of its
         * parts. They are stats for the reason the rest are: a perk that makes
         * chrome run cooler moves them, and it should not need deck code to do
         * it.
         */
        'deck_module_thermal_warning' => [
            'tags' => ['deck', 'thermal', 'tolerance'],
            'min' => 0,
            'max' => null,
            'rounding' => 'floor',
        ],

        'deck_module_thermal_limit' => [
            'tags' => ['deck', 'thermal', 'tolerance'],
            'min' => 0,
            'max' => null,
            'rounding' => 'floor',
        ],

        /*
         * The floor of 1 is the same rule hitpoints_max has, and it is what
         * makes FLATLINE survivable as a mechanic: an ICE program tagged
         * flatline does PERMANENT damage to this ceiling, as an unexpiring
         * modifier rather than a number written into a column. Without a floor,
         * accumulated injury would end a character by arithmetic rather than by
         * anything anyone decided. Only damage reaches 0.
         *
         * See docs/CYBERSPACE.md, "Losing", and docs/MECHANICS.md, "Flatline is a
         * permanent stored modifier".
         */
        'synaptic_integrity_max' => [
            'tags' => ['derived', 'vital'],
            'min' => 1,
            'max' => null,
            'rounding' => 'hundredth',
        ],

        /*
         * The ceiling of fatigue, where a character collapses. Not tagged `vital`: it is not a pool of hit points,
         * and fatigue is outside Character::RESOURCES. Floor of 1 for the reason the other two have one.
         */
        'fatigue_max' => [
            'tags' => ['derived'],
            'min' => 1,
            'max' => null,
            'rounding' => 'hundredth',
        ],

        /* Points of fatigue that come off every minute. Zero is the floor: nothing here makes you more tired by resting. */
        'fatigue_recovery' => [
            'tags' => ['derived'],
            'min' => 0,
            'max' => null,
            'rounding' => 'hundredth',
        ],

        /* Points of addiction that come off every family's book each minute (`characters:regenerate`). Zero for everybody: only an
           effect carrying it (a Script, say) works the need off faster than the nightly day off. */
        'addiction_recovery' => [
            'tags' => ['derived'],
            'min' => 0,
            'max' => null,
            'rounding' => 'hundredth',
        ],

        /* The percent of each point of fatigue that is actually taken. Never under 10, like crafting_cost. */
        'fatigue_taken' => [
            'tags' => ['derived'],
            'min' => 10,
            'max' => null,
            'rounding' => 'none',
        ],

    ],

    /*
    |----------------------------------------------------------------------
    | Checks
    |----------------------------------------------------------------------
    |
    | Every skill implies a check — `check.<slug>` — and this is how one is
    | built. There is no per-skill entry anywhere: a check reads the skill's
    | OWN primary and secondary attributes, which the skills table already
    | declares because training speed needed them, and its trained level.
    |
    |     check = primary * primary_weight
    |           + secondary * secondary_weight
    |           + level * level_weight
    |
    | Weights rather than the divisors `formulas` uses, because an attribute
    | runs 0..100 and a level runs 0..5: summing them straight would give an
    | attribute with a rounding error attached.
    |
    | Every input is RESOLVED before it gets here, so an implant that raises
    | Perception raises every Perception-governed check with no further wiring.
    |
    | Expected to be tuned. With a mid-career runner — primary 40, secondary
    | 30, level 3 — this gives 20 + 7.5 + 24 = 51. App\Stats\SkillStats is
    | the only thing that reads it.
    |
    */
    'checks' => [
        'primary_weight' => 0.5,
        'secondary_weight' => 0.25,
        'level_weight' => 8.0,
    ],

    /*
    |----------------------------------------------------------------------
    | Derived formulas
    |----------------------------------------------------------------------
    |
    | Each derived stat is a sum of terms, one per attribute:
    |
    |     term = ceil(attribute / divisor)
    |
    | so a divisor of 1 contributes the attribute itself and 2 contributes
    | half of it, rounded up.
    |
    | These are DATA rather than code because the character-creation screen
    | has to compute the same numbers in the browser as it moves the sliders.
    | Both sides read this array, so the formula exists once and the two
    | cannot drift. DerivedStatTest checks that agreement by running the
    | shipped JavaScript against the PHP.
    |
    */
    /*
    |----------------------------------------------------------------------
    | Pools
    |----------------------------------------------------------------------
    |
    | A derived base that SUMS its attributes and divides once, which is the
    | other way round from `formulas` below — and the difference is not a
    | detail. A formula divides each attribute, rounds each UP, and adds the
    | results, so four attributes of 20 over a divisor of 8.5 come to 12. A
    | pool adds first: 80 over 8.5 is 9.4. The first is generous by design
    | because hit points and Synaptic Integrity are pools you spend; the second
    | is an average, and an average is what a capability reads like.
    |
    | Kept apart from `formulas` rather than folded in, because `formulas` is
    | shipped to the character-creation screen as data and recomputed there in
    | JavaScript — one shape, asserted by DerivedStatTest. A second shape in
    | that array would have to be mirrored in the browser as well, and nothing
    | here is shown at creation.
    |
    | The inputs are RESOLVED, like a formula's: an implant that raises
    | Perception raises stealth, with no further wiring.
    */
    /*
    |----------------------------------------------------------------------
    | Bases
    |----------------------------------------------------------------------
    |
    | A starting value for a stat that is neither computed from attributes nor
    | stored on the character: it begins at a number and everything it is worth
    | comes from modifiers. The third map `App\Stats\Sheet::baseFor()` walks,
    | after pools and before formulas.
    |
    | It exists so `Character::stat()` can answer for this family at all.
    | Without an entry a declared stat with no pool and no formula THROWS when
    | asked through a Sheet — which is a loud failure rather than a wrong
    | answer, but still a landmine under the obvious way to ask.
    |
    | The older member of the family is deliberately not listed:
    | `icebreaker_damage` hands in zero at its call site. Listing it here would
    | change how it resolves for the sake of tidiness, which is not a reason.
    */
    'bases' => [

        // Flat, since there is no dodge. Against `aim` of about 13 at attributes of 20 and the logistic's
        // spread of 8, a starting character is hit about two times in three by an even match.
        'defence' => 8,
        'pursuit' => 8,

        // Two per cent of a player's hits are critical, in either realm.
        'critical_chance_physical' => 2,
        'bleed_per_level' => 0.5,
        'critical_chance_cyberspace' => 2,

        // Nobody routes a run through anything until a skill says otherwise.
        // Routing and Advanced Routing grant one hop per level each.
        'routing_hops' => 0,

        // Half a point of heat comes off every night on its own. Nothing in
        // the catalogue raises it yet; when something does, it is a modifier
        // on this id and nothing else changes.
        'heat_decay' => 0.5,

        // A point of Synaptic Integrity every two hours, for everybody.
        'synaptic_mend_minutes' => 120,

        // One decryption cycle is five minutes before anything shortens it.
        'decryption_cycle_seconds' => 300,

        'crafting_slots' => 1,
        'crafting_cost' => 100,

        // Half a point of fatigue a minute comes off on its own. 500 minutes, a little over eight hours, empties 260.
        'fatigue_recovery' => 0.5,

        // Every point of fatigue added is scaled by this as a percent: 100 is as written, a stimulant or a Meat skill
        // takes a share off it.
        'fatigue_taken' => 100,
    ],

    /*
    | DIMINISHING RETURNS ON THINGS OF ONE TYPE.
    |
    | The share the Nth modifier in a stack contributes, strongest first: two
    | scanners are not twice one scanner, whether or not they are the same
    | program. A stack is things of one TYPE (for software, one program kind)
    | doing the same op to the same target. Past the end of the list: NOTHING.
    | The first four are the design; the tail continues the same curve down to
    | where it stops mattering.
    |
    | Read by `App\Stats\Sources\RunningPrograms` and applied through
    | `Modifier::scaledBy()`. This is the rule for things of one type stacking
    | ANYWHERE: the next source that can hold two reads this list rather than
    | inventing its own.
    */
    'diminishing' => [1.0, 0.86, 0.57, 0.26, 0.10, 0.03],

    'pools' => [

        /*
        | Stealth = (Intellect + Focus + Memory + Perception) / 8.5.
        |
        | Four attributes because being hard to notice in somebody else's
        | machine is not one faculty: knowing what a system expects to see
        | (intellect), holding a pattern steady (focus), remembering what you
        | already touched (memory), and noticing what is watching (perception).
        |
        | 8.5 puts a starting character around 9 and a very good one around 12,
        | which against the IDS table is a coin flip at grade 1 and next to
        | nothing by grade 5 — so the base is a floor to build on rather than a
        | way past anything. Obfuscation adds to it flat and evasion programs
        | are meant to carry the rest.
        */
        'takedown' => [
            'attributes' => ['muscle', 'motor'],
            'divisor' => 3.0,
        ],

        'stealth' => [
            'attributes' => ['intellect', 'focus', 'memory', 'perception'],
            'divisor' => 8.5,
        ],

        /*
        | Egress = (Reflex + Focus + Intellect) / 4.
        |
        | Three attributes, and deliberately not stealth's four: getting out is
        | a different act from not being seen in the first place, so a
        | character built for one is only partly built for the other.
        |
        |   reflex     how fast the disconnect happens once it has been
        |              decided. The term stealth does not have, and the one
        |              that makes a physically quick character good at leaving
        |              a machine they were never subtle in.
        |   focus      holding the pattern together while something is actively
        |              tearing at it. The composure half of the attribute, the
        |              same half Synaptic Integrity reads.
        |   intellect  knowing the way out. A machine's exits are its own and
        |              finding one under pressure is comprehension, not speed.
        |
        | MEMORY IS ABSENT ON PURPOSE. Remembering what you already touched is
        | what keeps you unseen on the way in; it does nothing for you once
        | something has hold of you.
        |
        | 4 puts a starting character near 14 and a very good one near 19,
        | which against `cyberspace.egress.difficulty` is out-in-a-turn at the
        | bottom of the grid and a real decision from the middle up. Three
        | attributes over 4 is a heavier divisor per term than stealth's four
        | over 8.5 — that is what keeps the two on the same scale.
        */
        'egress' => [
            'attributes' => ['reflex', 'focus', 'intellect'],
            'divisor' => 4.0,
        ],

        /*
        | Exploration = (Focus + Perception + Senses) / 4.
        |
        | Noticing what is there, holding attention on it, and the raw senses
        | that take it in. Four is egress's own divisor for three attributes,
        | which puts a starting character near 14 — what the concealment
        | difficulties in config/city.php are measured against.
        */
        'exploration' => [
            'attributes' => ['focus', 'perception', 'senses'],
            'divisor' => 4.0,
        ],

        /*
        | Aim = (Motor + Senses) / 3, ONE pool shared by every weapon class (`aim.pistol`, `aim.blade`,
        | and so on; see App\Stats\CombatStats).
        |
        | Steady hands and the eye to use them. Three for two attributes puts a starting character near
        | 13 — the scale `bases.defence` and an NPC's own `aim` are measured against, and the same
        | neighbourhood egress and exploration sit in. Skills are parked, so the class changes nothing
        | here yet; it is a separate stat so a skill can be one modifier on it later.
        */
        'aim' => [
            'attributes' => ['motor', 'senses'],
            'divisor' => 3.0,
        ],
    ],

    'formulas' => [

        /*
         * HP = Meat + half of Muscle + half of Focus.
         *
         * Hitpoints are the ability to endure physical punishment and pain.
         *
         *   meat    how tough and how big the body is. The whole of it, and
         *           the only term at full weight.
         *   muscle  strength and physique.
         *   focus   concentration AND willpower. Focus stands for both, and
         *           this formula draws on the second: someone with the will to
         *           keep going endures a great deal more than someone without
         *           it. It is not a mental attribute doing borrowed work — it
         *           is one attribute with two faces, and the physical pool
         *           reads the one the mental pool does not.
         *
         * Written down because it is the term nobody guesses. The willpower
         * half is invisible from Focus's own description, which speaks only of
         * sustained attention, so this looks like a copy-paste from the SI
         * formula below until you know what the attribute actually covers.
         */
        'hitpoints_max' => [
            'meat' => 1,
            'muscle' => 2,
            'focus' => 2,
        ],

        /*
         * SI = half of Focus + half of Memory + half of Intellect + half of Meat.
         *
         * Synaptic Integrity is what holds together when a mind is exposed to
         * something it was not built to see. Nothing at full weight: no single
         * faculty carries it.
         *
         *   focus      holding together under exposure — the composure half of
         *   intellect  the attribute, alongside the ability to make sense of
         *              what is happening. Both keep a mind intact where panic
         *              would not.
         *   memory     experience to draw on, and how RESILIENT the mind is.
         *              Having seen bad things before is part of surviving the
         *              next one.
         *   meat       the physical robustness of the brain and nervous system.
         *              SI is damage to wetware, and wetware is meat.
         *
         * NOTE THE SYMMETRY, and that it is deliberate: each pool reaches
         * across. Hitpoints take a mental term (Focus, as willpower); Synaptic
         * Integrity takes a physical one (Meat, as the tissue doing the
         * thinking). Neither list is the pure physical or pure mental set, and
         * a change that "tidied" either into one would be removing the point.
         */
        'synaptic_integrity_max' => [
            'focus' => 2,
            'memory' => 2,
            'intellect' => 2,
            'meat' => 2,
        ],

        /*
         * Fatigue max = (Focus x 2 + Meat x 2 + Social / 3) x 3, which is 6 Focus + 6 Meat + Social. A formula can
         * only divide, so the six is a divisor of one sixth, written as 0.166667: 6n / 6 stays exactly 6n after the
         * round-up for every attribute up to the ceiling (n x 6 x 0.9999982 never drops a whole point). Attributes of
         * 20 give 260.
         */
        'fatigue_max' => [
            'focus' => 0.166667,
            'meat' => 0.166667,
            'social' => 1,
        ],

    ],

];
