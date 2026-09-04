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
         * HEAT. What the deck makes, and what is taken back out of it. There
         * is no third number for what a deck can stand: a deck stands any
         * amount of heat, and what the heat does is done to the modules —
         * each against its own thresholds, which are stats of their own below.
         *
         * Both are stats for the reason the others are: a skill that runs a
         * deck cool is exactly the kind of thing this system exists for.
         */
        'deck_heat' => [
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
        'deck_module_heat_warning' => [
            'tags' => ['deck', 'thermal', 'tolerance'],
            'min' => 0,
            'max' => null,
            'rounding' => 'floor',
        ],

        'deck_module_heat_limit' => [
            'tags' => ['deck', 'thermal', 'tolerance'],
            'min' => 0,
            'max' => null,
            'rounding' => 'floor',
        ],

        'synaptic_integrity_max' => [
            'tags' => ['derived', 'vital'],
            'min' => 0,
            'max' => null,
            'rounding' => 'floor',
        ],

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
