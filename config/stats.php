<?php

declare(strict_types=1);

use App\Models\Character;

/*
|--------------------------------------------------------------------------
| Stat registry
|--------------------------------------------------------------------------
|
| Every number the modifier system can touch is declared here exactly once.
| See MECHANICS.md for the rules; the short version:
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
         * still specified-only (MECHANICS.md, step 4). Declaring it as a stat
         * is enough for modifiers to target it today.
         *
         * The floor of 1 answers MECHANICS.md open question 2 provisionally: a
         * debuff must never drive max HP to 0 and kill a character as a side
         * effect of losing an implant. Only damage reaches 0.
         */
        'hitpoints_max' => [
            'tags' => ['derived', 'vital'],
            'min' => 1,
            'max' => null,
            'rounding' => 'floor',
        ],

        /*
         * The _max suffix is a naming rule, not decoration: these are DERIVED
         * ceilings, computed and never stored. The matching current values
         * (hitpoints_current, synaptic_integrity_current) are RESOURCES —
         * stored state, clamped down by these. Neither resource exists yet.
         * See MECHANICS.md, "Derived stats vs resources".
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
         * Rounding down for the same reason max HP does — these are whole
         * units of hardware.
         */
        'deck_qflops' => [
            'tags' => ['deck', 'compute'],
            'min' => 0,
            'max' => null,
            'rounding' => 'floor',
        ],

        'deck_quantabits' => [
            'tags' => ['deck', 'memory'],
            'min' => 0,
            'max' => null,
            'rounding' => 'floor',
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
        | How many passes a scan gets before the picture it has is the picture
        | it gets.
        |
        | A STAT RATHER THAN A CONSTANT because scanning software comes in
        | versions, and a better one buying another look at the machine is the
        | clearest thing a version can be worth. The base is
        | `cyberspace.scan.passes` and a program, a skill or an implant moves it
        | like anything else.
        |
        | Floored at 1: a character holding something that took their passes to
        | nothing would be holding a program that stops them scanning, which is
        | not a trade anybody would make on purpose.
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
        | How long somebody stays on the floor, in minutes.
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
        'recovery_minutes' => [
            'tags' => ['derived', 'vital'],
            'min' => 0,
            'max' => null,
            'rounding' => 'ceil',
        ],

        /*
        | Synaptic Integrity recovered per minute, on its own.
        |
        | ZERO BY DEFAULT, AND THAT IS THE RULE RATHER THAN A STARTING VALUE:
        | nothing in this game heals by waiting. Synaptic Integrity and hit
        | points come back through things a player DOES, and the one exception
        | is bought with a rank 16 skill — Neuroplasticity, a point a minute per
        | level. A character who has not trained it recovers nothing by sitting
        | still, ever.
        |
        | Per MINUTE rather than per tick, so whatever eventually credits it can
        | do so lazily off elapsed time the way a training deposit does. Nothing
        | credits it yet.
        */
        'synaptic_regen' => [
            'tags' => ['derived', 'vital'],
            'min' => 0,
            'max' => null,
            'rounding' => 'floor',
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
            'rounding' => 'floor',
        ],

        'coop_slots' => [
            'tags' => ['cyberspace'],
            'min' => 0,
            'max' => null,
            'rounding' => 'floor',
        ],

        'scan_duration' => [
            'tags' => ['scan'],
            'min' => 1,
            'max' => null,
            'rounding' => 'floor',
        ],

        'scan_passes' => [
            'tags' => ['scan'],
            'min' => 1,
            'max' => null,
            'rounding' => 'floor',
        ],

        'deck_storage' => [
            'tags' => ['deck', 'storage'],
            'min' => 0,
            'max' => null,
            'rounding' => 'floor',
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
         * See CYBERSPACE.md, "Losing", and MECHANICS.md, "Flatline is a
         * permanent stored modifier".
         */
        'synaptic_integrity_max' => [
            'tags' => ['derived', 'vital'],
            'min' => 1,
            'max' => null,
            'rounding' => 'floor',
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

    ],

];
