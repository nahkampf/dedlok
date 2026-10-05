<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | What an entity thinks of you
    |--------------------------------------------------------------------------
    |
    | Standing runs from -100 to 100 and is stored to three decimals, because
    | what earns it is small: a mission is worth hundredths of a point and the
    | nightly drift takes half a percent. Clamped on the way in by
    | App\Standing\StandingLedger, which writes what LANDED rather than what
    | was asked for — so the books always equal the sum of the ledger.
    |
    | NOTHING HERE IS BALANCED. There is no mission to earn it with yet, so
    | every number below is a first pass chosen to make the shape legible.
    |
    */

    'range' => [
        'min' => -100.0,
        'max' => 100.0,
    ],

    /*
    |--------------------------------------------------------------------------
    | What each thing is worth
    |--------------------------------------------------------------------------
    |
    | One entry per App\Standing\StandingReason. A reason with no entry is
    | worth nothing, which is the honest answer for a cause nobody has priced.
    |
    | The negatives are the only ones that can happen today: missions and the
    | store are designed in TODO.md and not built, and their entries wait for
    | the writers that will use them.
    |
    | Being NOTICED costs least — it is being seen rather than what you did.
    | A raid is the ordinary cost of taking something. A BURN is worth twenty
    | raids, because the system never comes back and the entity knows exactly
    | what it is looking at. A door found is between the two: it is evidence
    | that you were inside and stayed.
    |
    */

    'reasons' => [
        'noticed' => -0.02,
        'raided' => -0.10,
        'backdoor_found' => -0.05,
        // A completed trace: they know who you are. PER GRADE of the tracing
        // system (grade 1 is -0.05, grade 9 is -0.45), so it stays in step with
        // what the same grade's missions pay. Heat scales the same way.
        'traced' => -0.05,
        'burned' => -1.00,

        // Beating one of their people in a fight. PER GRADE of the opponent (grade 1 is -0.05, grade 5 is
        // -0.25), like a trace. Only a WIN moves it: getting away, or being beaten, does not.
        'defeated' => -0.05,

        // Written by the mission writer with an amount of its own, scaled by
        // the agent's grade — this is the floor it falls back to.
        'mission_completed' => 0.01,

        // Proportional to what was spent; the ratio lives with the store,
        // below. This is the floor it falls back to when nothing is passed.
        'store_spend' => 0.0,

        // Computed per row by the drift, never read from here.
        'decay' => 0.0,
    ],

    /*
    |--------------------------------------------------------------------------
    | The nightly drift
    |--------------------------------------------------------------------------
    |
    | PROPORTIONAL, not flat: a quarter of a percent of what is carried, toward
    | zero, from both directions. A flat rate at the size of a mission's reward
    | would be backwards — it would erase a day's work at the bottom of the
    | ladder and cost nothing at the top.
    |
    | Standing settles where a day's earnings equal the drift, which is 400x
    | the daily rate — a soft ceiling on every rung without another rule. It
    | was half a percent, and halved so that standing once earned is kept:
    | a month away from 60 costs about four points, and half of anything
    | carried takes some nine months to fade. The same holds for a grudge.
    | See config/missions.php for what the climb looks like against it.
    |
    */

    'drift' => [
        'nightly' => 0.0025,
    ],

    /*
    |--------------------------------------------------------------------------
    | Bands
    |--------------------------------------------------------------------------
    |
    | What a number means, in words. A card shows the band and the entity's own
    | screen shows the number: seven steps because that is as many distinctions
    | as anybody can hold. `at` is the FLOOR of each band, and a LIST rather
    | than a map keyed by that floor because PHP casts a float array key to an
    | int — which silently collapsed -0.001 and 0.0 into one band.
    |
    | The two thresholds that matter mechanically are not here — the store and
    | the missions read `exchanges.min_standing`, which is a column, because a
    | gate is a property of the desk rather than of the vocabulary.
    |
    */

    'bands' => [
        ['at' => -100.0, 'band' => 'hostile'],
        ['at' => -60.0, 'band' => 'hated'],
        ['at' => -20.0, 'band' => 'cold'],
        ['at' => 0.0, 'band' => 'neutral'],
        ['at' => 5.0, 'band' => 'known'],
        ['at' => 20.0, 'band' => 'trusted'],
        ['at' => 60.0, 'band' => 'allied'],
    ],

    /*
    |--------------------------------------------------------------------------
    | The loyalty store
    |--------------------------------------------------------------------------
    |
    | Spending at an entity's own shelf is a courtesy rather than a service, so
    | it nudges their opinion without ever being a way to BUY standing.
    |
    | At this ratio it takes a thousand loyalty to move standing by a single
    | point, and a thousand loyalty is a great many missions — which is the
    | point. Loyalty never decays and is earned only by finishing work, so it
    | is the store's whole scarcity; there is no stock column anywhere in it.
    |
    */
    'store' => [
        'standing_per_loyalty' => 0.001,
    ],

];
