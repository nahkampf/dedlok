<?php

/*
|--------------------------------------------------------------------------
| Safehouses
|--------------------------------------------------------------------------
|
| FATIGUE.md, *Safehouses*.
*/

return [

    /*
     * The slug of the safehouse everybody falls back to: what a character with `safehouse_id` NULL has, and where a
     * collapse wakes them. It lives in Knightsbridge Projects. The editor refuses to delete it.
     */
    'fallback' => 'cardboard-box',

    /* Explore passes in a district before its safehouses can be rented (counted in character_district_passes). */
    'passes_needed' => 5,

    /* Rent is per this many hours, paid in advance; one more period unpaid and the safehouse is lost. */
    'rent_hours' => 24,
    'grace_hours' => 24,

    /*
     * Resting while on drugs: the `wakefulness` of the doses in effect adds up, and at the limit they cannot rest. A dose
     * of an `overridden_by` family in effect lets them rest anyway.
     */
    'rest' => [
        'wakefulness_limit' => 10,
        // Synaptic Integrity mended per minute of resting, whatever the home.
        'synaptic_per_minute' => 0.083,
        'overridden_by' => ['opiate', 'medical'],
    ],

    /* Hours to collect what was stored in a home that was lost, before it is deleted. */
    'storage_hours' => 48,

    /*
     * What each action does. PLACEHOLDERS until balance. Cooldowns are seconds. Resting has no entry: its rate is the
     * safehouse's own `rest_rate`.
     */
    'actions' => [
        'patch' => ['hitpoints' => 10, 'cooldown' => 14400],
        'meditate' => ['synaptic_integrity' => 10, 'fatigue' => 10, 'cooldown' => 3600],
        'shower' => ['fatigue' => 15, 'addiction' => 1, 'cooldown' => 28800],
    ],

    /*
     * Finding, working out and breaking into another player's home. PLACEHOLDERS until balance. A pass of exploring may
     * sense one (`exploration` against `find.difficulty`, floored at `checks.floors.safehouse_find`). A lead is worked
     * out by stakeouts and asking around (discovery against the home's stealth) and scouted (security against its
     * security), then broken into by a check: `stealth` plus `attribute_weight` of Perception and Senses against the
     * home's security, plus `gap_weight` of whatever the finder has not scouted yet, all times `difficulty_scale`.
     */
    'leads' => [
        'find' => ['difficulty' => 60],
        'watch_seconds' => 60,
        'max_catchup' => 600,
        'stakeout' => ['nothing' => 50, 'small' => 40, 'small_gain' => 0.1, 'large_gain' => 0.8],
        'scope_gain' => 3,
        'ask' => [
            'nothing' => 60, 'clues' => 15, 'misdirected' => 15, 'nutjob' => 10,
            'clue_creds' => [5, 15], 'nutjob_creds' => [5, 25], 'clue_gain' => 2, 'misdirected_loss' => 2,
        ],
        'difficulty_scale' => 0.2,
        'gap_weight' => 0.5,
        'attribute_weight' => 0.25,
        'steal_rows' => 2,
        'raid_cooldown_hours' => 24,
        'police_chance' => 15,
    ],

];
