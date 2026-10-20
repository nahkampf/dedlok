<?php

declare(strict_types=1);

namespace App\Missions;

use App\Support\Text;

/**
 * What a job asks of a runner.
 *
 * FIVE, AND EACH ONE IS A DIFFERENT KIND OF EVIDENCE. That is the whole reason
 * the list is short and fixed: `mission_targets.satisfied_at` is one column
 * filled several ways, and an action is the thing that decides which way.
 *
 *  - SCAN carries evidence IN — a file, made before or during the job, spent
 *    at the desk.
 *  - BURN cannot carry anything, because the subject is gone; the act records
 *    itself as it happens.
 *  - PLANT is the same shape as burn: the install writes the flag, and
 *    nothing in the world belongs to the runner afterwards.
 *  - AGITATE is recorded where it happens, when a run that woke the ICE leaves and the
 *    machine shuts because of it (`RunStarter::end()`). Only systems with ICE are named.
 *  - EXTRACT names one system whose run is GUARANTEED to hold a job file for the runner. They pull it, decrypt it
 *    and hand the decrypted file in (`MissionLedger::handInExtract()`).
 *  - HEIST is recorded where it happens: the creds a runner takes off a named system are held on the job
 *    (`missions.proceeds`) instead of the wallet, and the desk pays a cut of them back at hand-in.
 *  - TRANSPORT hands the runner a container at the desk and names a system by COORDINATES, not address (an
 *    address could be jumped to, skipping the hunter the job summons). The delivery is its own act, not a desk
 *    hand-in (`MissionLedger::deliver()`).
 *  - DENIAL is recorded where it happens: a Denial run whose flood meter fills (`Simulator::flood()`).
 *  - SUPPLY names no systems at all. It buys intel by the file, which is what
 *    makes scanning an occupation rather than a chore between runs.
 */
enum MissionAction: string
{
    case Scan = 'scan';
    case Burn = 'burn';
    case Plant = 'plant';
    case Agitate = 'agitate';
    case Extract = 'extract';
    case Heist = 'heist';
    case Transport = 'transport';
    case Supply = 'supply';
    case Denial = 'denial';

    /**
     * How many systems this action names, as a range.
     *
     * Supply names NONE — its size is a quantity of files instead, which is
     * why `targets()` and `quantity()` are separate questions and each action
     * answers exactly one of them.
     *
     * @return array{int, int}
     */
    public function targets(): array
    {
        return match ($this) {
            self::Scan => [3, 10],
            self::Burn => [1, 2],
            self::Plant => [1, 3],
            self::Agitate => [1, 2],
            self::Extract => [1, 1],
            self::Heist => [1, 3],
            self::Transport => [1, 1],
            self::Supply => [0, 0],
            self::Denial => [1, 2],
        };
    }

    /** Does a job of this kind designate systems, or ask for a count of something? */
    public function designatesTargets(): bool
    {
        return $this !== self::Supply;
    }

    /**
     * Whether the evidence is carried to the desk or recorded where it happens.
     *
     * A carried action is satisfied at HAND-IN, against what the runner is
     * holding. A recorded one is satisfied by the act itself, in the same
     * transaction that performs it — see `Burner` and `Backdoors`.
     */
    public function evidenceIsCarried(): bool
    {
        return $this === self::Scan || $this === self::Supply || $this === self::Extract;
    }

    public function label(): string
    {
        return Text::get("missions.actions.{$this->value}");
    }

    public function description(): string
    {
        return Text::get("missions.action_hint.{$this->value}");
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
