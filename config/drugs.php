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
        'tolerance' => 3.0,
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
    | Three separate things land, and the table below says which: the ceiling
    | comes down as an injury, the body takes ordinary damage, and past a
    | family's threshold the heart stops.
    */
    'overdose' => [
        'base' => 2,
        'per_tolerance' => 25.0,
        'damage_per_excess' => 2.0,

        /*
        | WHAT TOO MUCH DOES, per family.
        |
        | Three things happen at once and they are deliberately different
        | kinds of thing:
        |
        |   the CEILING comes down       `damage_per_excess` above, as an
        |                                injury — permanent until a clinic
        |                                exists, and not mitigable, because
        |                                mitigation has nothing to say about
        |                                a ceiling
        |   the BODY takes it            `hp_per_excess` here, as ordinary
        |                                damage through DamageResolver, so a
        |                                constitution can be written against
        |                                `all_of: ['damage', 'overdose']`
        |   the HEART STOPS              at `lethal_at` excess doses, and
        |                                null means this family never does
        |
        | LETHALITY IS THE FAMILY'S, because it is pharmacology rather than
        | branding: opiates and sedatives stop you breathing and do it soonest,
        | a stimulant has to work harder at it, and nothing about a psychedelic
        | stops a heart. A specific drug that disagrees overrides it with
        | `drugs.lethal_at` on its own row.
        |
        | A FLATLINE IS NOT BIG DAMAGE. It empties the body outright and does
        | not come through the damage door, the same call the ICE attack tag of
        | the same name makes: a resistance is about how hard something hits,
        | and a heart that has stopped is not a matter of degree. What it costs
        | is the waiting screen and whatever the injury already took.
        |
        | Nothing here is balanced. The numbers are chosen so that the third
        | simultaneous dose is a bad afternoon and the fifth is an ending.
        */
        'families' => [
            'stimulant' => ['hp_per_excess' => 4, 'lethal_at' => 3],
            'opiate' => ['hp_per_excess' => 3, 'lethal_at' => 2],
            'sedative' => ['hp_per_excess' => 3, 'lethal_at' => 2],
            'psychedelic' => ['hp_per_excess' => 2, 'lethal_at' => null],
            // Too much makes you ill and never stops a heart.
            'nootropic' => ['hp_per_excess' => 1, 'lethal_at' => null],
            // Stacked treatment kills: the same figures as a stimulant (decided 2026-10-07).
            'medical' => ['hp_per_excess' => 4, 'lethal_at' => 3],
        ],
    ],
];
