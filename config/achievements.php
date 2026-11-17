<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | What is worth unlocking
    |--------------------------------------------------------------------------
    |
    | One entry per achievement: the metric it watches and the value that wins
    | it. The call sites never name an achievement — they report what happened
    | and this table decides whether that crossed a line, so a new achievement
    | over an existing metric is one row here and no PHP at all.
    |
    | THE KEY IS THE ID, AND IT IS PERMANENT: `user_achievements.achievement_id`
    | stores it and `public/images/achievements/<id>.jpg` is found by it, so
    | renumbering one would hand somebody else's picture to everybody who has
    | it. Retire an achievement by deleting the row and never reusing the
    | number. Each entry also carries its `name` (placeholders until they are written) and
    | its `description` (under 255 characters), so one file holds everything about an achievement.
    |
    | A threshold is read against the RUNNING TOTAL, so an achievement added
    | later is won by a player who already passed it the next time the metric
    | moves at all.
    |
    | `secret` decides whether it is listed before it is won. An open one is on
    | the page from the first day as something to go and do; a secret one is
    | not there at all until it is unlocked, and then it is. It changes nothing
    | about how one is EARNED — `Achievements::listableFor()` is the only thing
    | that reads it, so a secret achievement unlocks exactly as loudly as any
    | other. Absent means false: a new entry is visible unless it says not.
    |
    */

    'unlocks' => [

        // A million creds out of other people's systems.
        1 => [
            'name' => 'The Two Comma Club',
            'description' => 'A million creds extracted from runs.',
            'metric' => 'raid_creds',
            'threshold' => 1_000_000,
            'secret' => false,
        ],

        // Ten thousand drained quietly, a night at a time.
        2 => [
            'name' => 'Everything Counts In Large Amounts',
            'description' => 'Ten thousand creds drained using siphons.',
            'metric' => 'siphon_creds',
            'threshold' => 10_000,
            'secret' => false,
        ],

        /*
         * A routing chain at its maximum length. Ten is what the two routing
         * skills add up to — `routing_hops` has no ceiling of its own, so the
         * number lives here rather than being read off a stat that would
         * answer differently for every character.
         */
        3 => [
            'name' => 'Daisy Chain',
            'description' => 'A routing chain at its full length: ten relays between you and whoever is looking.',
            'metric' => 'routing_chain',
            'threshold' => 10,
            'secret' => false,
        ],

        // A hundred systems taken out of existence.
        4 => [
            'name' => 'Scorched Earth',
            'description' => 'A hundred systems burned.',
            'metric' => 'systems_burned',
            'threshold' => 100,
            'secret' => false,
        ],

        // A hundred pieces of ICE cut down to nothing.
        5 => [
            'name' => 'ICEBreaker',
            'description' => 'A hundred pieces of ICE defeated.',
            'metric' => 'ice_defeated',
            'threshold' => 100,
            'secret' => false,
        ],

        /*
         * Flatlined once, and flatlined a hundred times. The pair is the
         * shape most of these want: the same metric read at the moment it
         * first happens and again at the point it has become a career.
         */
        6 => [
            'name' => 'Code Blue',
            'description' => 'Flatlined. The first one is the one you remember.',
            'metric' => 'flatlines',
            'threshold' => 1,
            'secret' => false,
        ],
        7 => [
            'name' => 'Frequent Flyer',
            'description' => 'Flatlined a hundred times, and still jacking in.',
            'metric' => 'flatlines',
            'threshold' => 100,
            'secret' => false,
        ],

        // Twenty skills at the level cap, and fifty.
        8 => [
            'name' => 'Polymath',
            'description' => 'Twenty skills trained to the level cap.',
            'metric' => 'skills_at_max_level',
            'threshold' => 20,
            'secret' => false,
        ],
        9 => [
            'name' => 'Overqualified',
            'description' => 'Fifty skills trained to the level cap.',
            'metric' => 'skills_at_max_level',
            'threshold' => 50,
            'secret' => false,
        ],

        // A hundred systems read all the way through, and a thousand.
        10 => [
            'name' => 'A Scanner Darkly',
            'description' => 'A hundred systems fully scanned.',
            'metric' => 'systems_scanned',
            'threshold' => 100,
            'secret' => false,
        ],
        11 => [
            'name' => 'It\'s Honest Work',
            'description' => 'A thousand systems fully scanned.',
            'metric' => 'systems_scanned',
            'threshold' => 1_000,
            'secret' => false,
        ],

        // A thousand positions of ground explored, and ten thousand.
        12 => [
            'name' => 'Mapping the Unknown',
            'description' => 'A thousand positions of the grid explored.',
            'metric' => 'grid_explored',
            'threshold' => 1_000,
            'secret' => false,
        ],
        13 => [
            'name' => 'Panopticon',
            'description' => 'Ten thousand positions of the grid explored.',
            'metric' => 'grid_explored',
            'threshold' => 10_000,
            'secret' => false,
        ],

        // Ten places in the City found by exploring.
        14 => [
            'name' => 'The Knowledge',
            'description' => 'Ten locations in the City explored and discovered.',
            'metric' => 'locations_discovered',
            'threshold' => 10,
            'secret' => false,
        ],

        // A hundred siphons installed.
        15 => [
            'name' => 'Vampire',
            'description' => 'Installed a hundred siphons.',
            'metric' => 'siphons_installed',
            'threshold' => 100,
            'secret' => false,
        ],

        // Twenty-five thousand in bounties collected from other players. Unwinnable until PvP exists.
        16 => [
            'name' => 'Headhunter',
            'description' => 'Claimed 25,000 in bounties.',
            'metric' => 'bounties_claimed',
            'threshold' => 25_000,
            'secret' => false,
        ],

        // The price on your own head, at ten thousand.
        17 => [
            'name' => 'Wanted',
            'description' => 'A total of 10,000 in bounties on your head.',
            'metric' => 'bounty_on_head',
            'threshold' => 10_000,
            'secret' => false,
        ],

        // Fifty Bloodhounds broken.
        18 => [
            'name' => 'Indomitable',
            'description' => 'Defeated 50 Bloodhounds.',
            'metric' => 'bloodhounds_defeated',
            'threshold' => 50,
            'secret' => false,
        ],

        // A hundred jobs paid, and two thousand.
        19 => [
            'name' => 'Loyal Service',
            'description' => 'Completed 100 missions.',
            'metric' => 'missions_completed',
            'threshold' => 100,
            'secret' => false,
        ],
        20 => [
            'name' => 'The Grind',
            'description' => 'Completed 2000 missions.',
            'metric' => 'missions_completed',
            'threshold' => 2000,
            'secret' => false,
        ],

        // A run carried through on a grade 9 system.
        21 => [
            'name' => 'Netrunner',
            'description' => 'Made a successful run on a grade 9 system.',
            'metric' => 'run_grade_completed',
            'threshold' => 9,
            'secret' => false,
        ],

        // A thousand files broken open.
        22 => [
            'name' => 'Arne Beurling sends his regards',
            'description' => 'Decrypted 1000 files.',
            'metric' => 'files_decrypted',
            'threshold' => 1000,
            'secret' => false,
        ],

        // Hooked on a family, then clean of it.
        23 => [
            'name' => "Kickin' the habit",
            'description' => 'Got addicted and then got clean again.',
            'metric' => 'addictions_kicked',
            'threshold' => 1,
            'secret' => false,
        ],

        // Fifteen points of attribute earned at the gym, and fifteen at the books.
        24 => [
            'name' => 'Roy Natty',
            'description' => 'Increase physical attributes by 15 points through working out.',
            'metric' => 'workout_gains',
            'threshold' => 15,
            'secret' => false,
        ],
        25 => [
            'name' => 'Mentat',
            'description' => 'Increase mental attributes by 15 points through studying.',
            'metric' => 'study_gains',
            'threshold' => 15,
            'secret' => false,
        ],

        // Crash Jackout, over and over.
        26 => [
            'name' => 'Jack out or die',
            'description' => 'Crash jacked out 25 times.',
            'metric' => 'crash_jackouts',
            'threshold' => 25,
            'secret' => false,
        ],

        // Fifty fights won mostly by the Katana.
        27 => [
            'name' => 'I studied the blade',
            'description' => 'Won 50 fights where most of your damage came from a Katana.',
            'metric' => 'katana_wins',
            'threshold' => 50,
            'secret' => false,
        ],

        // A ranged weapon in hand is fine; the blade must have done most of the damage.
        28 => [
            'name' => 'Knife at a gun fight',
            'description' => 'Won 25 fights against opponents using firearms, with most of your damage coming from a blade.',
            'metric' => 'blade_vs_firearm_wins',
            'threshold' => 25,
            'secret' => false,
        ],

        // Fifty opponents stunned.
        29 => [
            'name' => 'Sleep tight',
            'description' => 'Successfully stunned 50 opponents.',
            'metric' => 'stuns',
            'threshold' => 50,
            'secret' => false,
        ],

        // Fifty opponents set burning.
        30 => [
            'name' => "Light 'em up",
            'description' => 'Caused burning on 50 opponents.',
            'metric' => 'burns',
            'threshold' => 50,
            'secret' => false,
        ],

        // Fifty opponents poisoned.
        31 => [
            'name' => 'Dr Feelbad',
            'description' => 'Caused poisoning on 50 opponents.',
            'metric' => 'poisonings',
            'threshold' => 50,
            'secret' => false,
        ],

        // A headshot counts only if it took the opponent to nought hit points or less.
        32 => [
            'name' => 'Canoeing',
            'description' => 'Landed 50 lethal headshots.',
            'metric' => 'lethal_headshots',
            'threshold' => 50,
            'secret' => false,
        ],

        // The most implants installed at one time.
        33 => [
            'name' => 'Borged up',
            'description' => 'Had 7 implants installed at the same time.',
            'metric' => 'implants_installed',
            'threshold' => 7,
            'secret' => false,
        ],

        // Seven different drugs; a drug taken twice is one.
        34 => [
            'name' => 'Psychonaut',
            'description' => 'Used 7 or more different drugs.',
            'metric' => 'drugs_tried',
            'threshold' => 7,
            'secret' => true,
        ],

        // Counts people paid, and only once more than 25,000 credits has gone out in all.
        35 => [
            'name' => 'Rainmaker',
            'description' => 'Transferred more than 25,000 credits in all, to more than 10 different people.',
            'metric' => 'rainmaker',
            'threshold' => 11,
            'secret' => true,
        ],

        // A Pod journey is counted when the fare is paid.
        36 => [
            'name' => 'Podracer',
            'description' => 'Took a Pod in the city 200 times.',
            'metric' => 'pod_trips',
            'threshold' => 200,
            'secret' => false,
        ],

        // Set when the `njord_released` flag is.
        37 => [
            'name' => 'Free Njordy',
            'description' => 'You released NJORD into Cyberspace.',
            'metric' => 'njord_released',
            'threshold' => 1,
            'secret' => false,
        ],

        // Counts physical fights won, whoever the opponent was. An escape does not count.
        38 => [
            'name' => 'Murder Hobo',
            'description' => 'Killed 150 opponents in combat.',
            'metric' => 'fights_won',
            'threshold' => 150,
            'secret' => true,
        ],

    ],

    // Rainmaker (35) only counts people paid once this many credits has been sent in all.
    'rainmaker_credits' => 25000,

];
