<?php

declare(strict_types=1);

namespace App\Achievements;

/**
 * What the game counts.
 *
 * A METRIC IS NOT AN ACHIEVEMENT. These are the running totals; which values
 * of them are worth something is `config/achievements.php`, and a metric with
 * no unlock hanging off it is perfectly ordinary — it is being counted for the
 * day one does.
 *
 * The values are stored in `achievement_progress.metric` and are therefore
 * PERMANENT. Retire one by leaving the case in place.
 */
enum Metric: string
{
    /** Creds taken out of systems. Siphons are not raiding and are counted apart. */
    case RaidCreds = 'raid_creds';

    /** Creds a siphon paid out. The safe half of the portfolio, counted separately. */
    case SiphonCreds = 'siphon_creds';

    /** Systems burned. */
    case SystemsBurned = 'systems_burned';

    /** Pieces of ICE cut down to nothing. */
    case IceDefeated = 'ice_defeated';

    /** Times flatlined. The injury is permanent; so is the count of them. */
    case Flatlines = 'flatlines';

    /**
     * Grid positions explored. GROUND rather than systems: it is the terrain
     * bitmap's own count, so empty ground counts exactly as much as ground
     * with something standing on it.
     */
    case GridExplored = 'grid_explored';

    /** City locations found by exploring (`Discovery::unlock()` news only). */
    case LocationsDiscovered = 'locations_discovered';

    /** Systems read all the way through. A pass that only half-answered is not one. */
    case SystemsScanned = 'systems_scanned';

    /**
     * How many skills stand at the level cap. HIGH-WATER, like the chain: it
     * is a state of the character rather than something that happened.
     */
    case SkillsAtMaxLevel = 'skills_at_max_level';

    /**
     * The longest routing chain ever held. HIGH-WATER, not a sum — it is a
     * state rather than an event, so it is reported with `reach()`.
     */
    case RoutingChain = 'routing_chain';

    /** Siphons installed: one per new backdoor of that kind, an event rather than a state. */
    case SiphonsInstalled = 'siphons_installed';

    /**
     * Creds of bounty collected by defeating another PLAYER who carried one. Nothing records it yet: there is no
     * player-versus-player fight, and a Bloodhound's payout is not a bounty claimed.
     */
    case BountiesClaimed = 'bounties_claimed';

    /** The bounty on your head, as it stands: a state, so it is reported with `reach()`. */
    case BountyOnHead = 'bounty_on_head';

    /** Missions handed in or delivered, one per job paid. */
    case MissionsCompleted = 'missions_completed';

    /** Bloodhounds (hunters) beaten, one per win. */
    case BloodhoundsDefeated = 'bloodhounds_defeated';

    /** The highest grade of system a run has been successfully completed on. HIGH-WATER: a state, reported with `reach()`. */
    case RunGradeCompleted = 'run_grade_completed';

    /** Files decrypted: one per file the decryption queue breaks open. */
    case FilesDecrypted = 'files_decrypted';

    /** Addictions kicked: one per drug family whose addiction fell to nothing after having reached the withdrawal line. */
    case AddictionsKicked = 'addictions_kicked';

    /** Physical attribute points gained by working out: one per full meter, whichever of the three it raised. */
    case WorkoutGains = 'workout_gains';

    /** Mental attribute points gained by studying: one per full meter, whichever of the three it raised. */
    case StudyGains = 'study_gains';

    /** Crash Jackouts: one per run left on purpose by tearing out of it. */
    case CrashJackouts = 'crash_jackouts';

    /** Fights won where most of the player's damage came from a Katana. */
    case KatanaWins = 'katana_wins';

    /** Fights won against an opponent carrying a firearm, where most of the player's damage came from a blade. */
    case BladeVsFirearmWins = 'blade_vs_firearm_wins';

    /** Opponents stunned: once per opponent per fight, however often it was re-applied. */
    case Stuns = 'stuns';

    /** Opponents set burning: once per opponent per fight. */
    case Burns = 'burns';

    /** Opponents poisoned: once per opponent per fight. */
    case Poisonings = 'poisonings';

    /** Headshots that took an opponent to nought hit points or less. A headshot that only hurt is not one. */
    case LethalHeadshots = 'lethal_headshots';

    /** The most implants installed at once. HIGH-WATER: a state of the body, reported with `reach()`. */
    case ImplantsInstalled = 'implants_installed';

    /** Different drugs ever taken (`Achievements::remember()` counts the set). HIGH-WATER. */
    case DrugsTried = 'drugs_tried';

    /**
     * How many different people were sent credits, counted ONLY once more than `achievements.rainmaker_credits` has gone out
     * in all: below that line it stays at nought. Two conditions, one metric, so the unlock is still one row.
     */
    case Rainmaker = 'rainmaker';

    /** NJORD set free: 1 once the character's `njord_released` flag is set (`Flags::set()` reports it). */
    case NjordReleased = 'njord_released';

    /** Journeys taken by Pod, one per fare paid. */
    case PodTrips = 'pod_trips';
}
