<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Hit quality
    |--------------------------------------------------------------------------
    |
    | How much of a weapon's full damage a hit does, by how well it landed. Weakest first;
    | `App\Combat\HitQuality` reads this and nothing else, so retuning the ramp is a number
    | here. A fraction of the damage, applied to the base BEFORE the damage profile splits it
    | and before resistance. Nothing is ever a clean miss: the lowest tier still does something.
    |
    | Unmeasured, like `checks.spread`.
    */
    'hit_quality' => [
        'near_miss' => 0.10,
        'glancing' => 0.25,
        'partial' => 0.50,
        'clean_hit' => 0.75,
        'dead_on' => 1.00,
    ],

    /*
    | How much luck matters in WHICH tier a shot gets (`HitQuality::fromRoll()`): the die moves the
    | chance by up to half of this either way before it is cut into five equal bands. At 0.6 the
    | average damage of a shot is almost exactly chance × damage; raise it for more variance.
    | Unmeasured — set with `fight:simulate`.
    */
    'spread' => 0.6,

    /*
    | Bare hands: what anybody fights with at Close when they carry no melee weapon, an NPC too.
    | A weapon like any other (`damage`, a profile summing to 100) without a row. Unmeasured.
    */
    'unarmed' => [
        'damage' => 2,
        'profile' => ['blunt' => 100],
    ],

    /*
    | Recoil. Every shot of a burst or automatic action after the first is this much worse than the
    | one before it, compounding: the chance of shot k (the first is 0) is multiplied by
    | (1 - burst_falloff)^k. See `App\Combat\Burst`.
    */
    'burst_falloff' => 0.07,

    /*
    | Where a hit lands: weights out of their own total, in the order `ArmourSlot::roll()` walks
    | them. Head 9, each arm 9, each leg 18, and the rest on the torso — 37%.
    */
    'body_parts' => [
        'head' => 9,
        'arm_left' => 9,
        'arm_right' => 9,
        'leg_left' => 18,
        'leg_right' => 18,
        'torso' => 37,
    ],

];
