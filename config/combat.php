<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Distance
    |--------------------------------------------------------------------------
    |
    | How far apart a fight is, in steps: a number, not a state. It opens at `start`, nought is close combat
    | and `max` is as far as anybody can get. NOTHING closes it by itself: only an action moves it. Create
    | distance wins one or two steps (`far_share` is the best share of its successful rolls that wins two:
    | 0.4 means the luckiest 40%), Close in wins one. Nobody can Disengage from `flee_beyond` or nearer, so to
    | run you first have to back off past it.
    |
    | Unmeasured, like everything else here.
    */
    'distance' => [
        'start' => 2,
        'max' => 6,
        'flee_beyond' => 2,
        'far_share' => 0.4,
    ],

    /*
    |--------------------------------------------------------------------------
    | Skill gates on weapons
    |--------------------------------------------------------------------------
    |
    | A weapon class named here can only be EQUIPPED by somebody whose resolved level in the skill (a slug)
    | is at least `gate_level`. A class left out is free to anyone. Asked by `WeaponWielder::wield()` and
    | nowhere else: an NPC's weapon is not a skill matter, and a weapon already in hand is never taken away.
    */
    'skill_gates' => [
        'pistol' => 'pistols',
        'smg' => 'smgs',
        'rifle' => 'rifles',
        'shotgun' => 'shotguns',
    ],

    'gate_level' => 1,

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

    /*
    | What a win pays in creds, by the opponent's grade: a uniform roll between the two, through the wallet
    | (`Reason::Fight`). A fled opponent, and one that got away, pays nothing. Unmeasured — set with
    | `fight:simulate`, against what a fight costs in hit points and what a clinic charges to mend them.
    */
    'pay' => [
        1 => ['min' => 20, 'max' => 60],
        2 => ['min' => 50, 'max' => 150],
        3 => ['min' => 120, 'max' => 350],
        4 => ['min' => 300, 'max' => 800],
        5 => ['min' => 700, 'max' => 2000],
    ],

    /*
    | The `mugged` tag: a whole percent, uniform between the bounds, of ALL the creds the player carries,
    | taken from somebody the opponent has put down. Floored to whole creds; a purse of nothing loses nothing.
    */
    'mugging' => ['min' => 30, 'max' => 50],

    /*
    | The `maim` tag: a whole percent, uniform between the bounds, of the player's MAXIMUM hit points added
    | to the `physical_trauma` injury (it deepens one row), until a clinic mends it. At least one point.
    */
    'maim' => ['min' => 10, 'max' => 20],

];
