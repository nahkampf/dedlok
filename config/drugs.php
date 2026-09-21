<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Drugs
|--------------------------------------------------------------------------
|
| The loop this describes: take one to get the effect, and the two books move.
| TOLERANCE costs you potency, so the next one does less. ADDICTION costs you
| the option of stopping, because past a threshold going without is a modifier
| you carry until you take another. Both come off slowly if you leave them
| alone.
|
| WHAT A DRUG IS worth is on the item — `items.modifiers`, like an implant —
| and what it COSTS is on its `drugs` row. Nothing here describes a drug;
| everything here is the rule every drug is read through.
|
| None of it is balanced. There is nothing to balance against: no drug is
| authored, nothing sells one, and the numbers below are chosen so the loop is
| legible at a glance rather than because anything measured them.
|
*/

return [

    /*
    | HOW LITTLE IT DOES FOR YOU ANY MORE, in bands.
    |
    | Read at the moment a dose is taken and frozen onto it. Each band is the
    | tolerance figure it starts at and the fraction it takes off, and ONLY off
    | the positive amounts: tolerance never softens what a drug does TO you,
    | which is the whole shape of the trap. The strongest band that applies
    | wins; below the first one a dose is worth what it says.
    |
    | At the default gain of five a dose, these land at five, ten and fifteen
    | doses — close enough together that somebody who takes one every day
    | notices within a fortnight, which is the point of the mechanic existing.
    */
    'tolerance' => [
        'bands' => [
            ['at' => 25.0, 'reduction' => 0.10],
            ['at' => 50.0, 'reduction' => 0.20],
            ['at' => 75.0, 'reduction' => 0.30],
        ],
    ],

    /*
    | WHEN GOING WITHOUT BECOMES SOMETHING YOU CARRY.
    |
    | Above this much addiction in a family, the character wears that family's
    | withdrawal — a `config/effects.php` definition, because a cause with no
    | row of its own is exactly what those are for. It is written when the
    | threshold is crossed and cleared by taking anything of the right family,
    | which is the only thing that clears it.
    |
    | THE ADDICTION FIGURE IS NOT TOUCHED BY TAKING ONE. Feeding it is not
    | curing it: the row goes away for as long as the need is met, the number
    | underneath stays exactly where it was, and only time brings it down.
    */
    'withdrawal' => [
        'threshold' => 40.0,
    ],

    /*
    | WHAT COMES OFF A DAY, for doing nothing at all.
    |
    | Tolerance falls faster than addiction, deliberately. A fortnight clean
    | gives the drug its strength back long before it gives the need up, so the
    | player who comes back after a break gets the good half first and the bill
    | afterwards — which is the honest shape of the thing being modelled.
    */
    'decay' => [
        'addiction' => 1.0,
        'tolerance' => 2.0,
    ],

    /*
    | HOW MANY OF ONE FAMILY A BODY HOLDS AT ONCE.
    |
    | Doses stack — two of a stimulant are two rows and they add up — so taking
    | another while the last is still working is the dangerous act, and it is
    | the act tolerance pushes somebody towards. Capacity is `base` plus one for
    | every `per_tolerance` points carried: tolerance costs potency and buys
    | headroom, which is what makes it worth having and worth fearing.
    |
    | DETERMINISTIC, AND THAT IS DELIBERATE. Almost everything else in this game
    | rolls, but a drug is a known quantity somebody puts into their own body on
    | purpose. The third one hurting when you knew two was the limit is a
    | mechanic; the third one hurting sometimes is a slot machine.
    |
    | The damage lands as `neural_damage`, the same injury a freeze and an ICE
    | flatline deepen — one row that a clinic heals one number of.
    */
    'overdose' => [
        'base' => 2,
        'per_tolerance' => 25.0,
        'damage_per_excess' => 2.0,
    ],
];
