<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\Ice;
use App\Models\SystemScan;
use App\Support\Text;

/**
 * The things a scan is trying to find out.
 *
 * docs/CYBERSPACE.md lists what a scan catalogues: the operating system, the grade,
 * the IDS, the ICE, the tracer, and any cloaked systems. Five of those exist —
 * the rest are phases away — and this is the one place that says which is
 * which.
 *
 * THE UNBUILT ONES ARE LISTED ANYWAY, and that is the same decision the item
 * card takes with *Put on market* and *Disassemble*: an answer that does not
 * exist yet says so, rather than being absent. A screen that grows rows as
 * features land is a screen nobody learns, and a player reading a scan should
 * be able to see the shape of what a scan will eventually tell them.
 *
 * When one of them arrives it becomes discoverable here, gains a column on
 * `system_scans`, and a line in `readFrom()`. Nothing else changes: the probe
 * rolls for whatever is unknown and discoverable, and the screen lists whatever
 * this enum holds.
 */
enum ScanFact: string
{
    case Grade = 'grade';
    case OperatingSystem = 'os';
    case Ids = 'ids';
    case Ice = 'ice';
    case Tracer = 'tracer';
    case Cloaked = 'cloaked';

    /**
     * Can a scan find this out today?
     *
     * False is not a gap in the scan — it is a thing the game does not have
     * yet. The probe never rolls for one of these and the screen shows it as
     * not yet rather than as unread, because *nobody* can read it.
     */
    public function isDiscoverable(): bool
    {
        return match ($this) {
            self::Grade, self::OperatingSystem, self::Ids, self::Tracer, self::Ice => true,
            self::Cloaked => false,
        };
    }

    /**
     * Has this been found out?
     *
     * COLUMNS ONLY, and that is the whole reason this is not just "is the
     * display value null". The operating system reads through a relation, and
     * a relation Eloquent loaded as null before the probe filled the column
     * stays null until something refreshes it — so asking the relation whether
     * a scan is complete says no to a scan that just answered everything.
     */
    public function isKnownOn(SystemScan $scan): bool
    {
        return match ($this) {
            self::Grade => $scan->grade !== null,
            self::OperatingSystem => $scan->os_skill_id !== null,
            // NOT `> 0`. Zero is an ANSWER here — this machine has none — and
            // reading it as unknown would send the probe back to roll again for
            // a fact it already has, for as many passes as the budget allows.
            self::Ids => $scan->ids_detection !== null,
            self::Tracer => $scan->tracer_detection !== null,
            // The LEVEL and not a row id, so a machine that burned does not
            // take the answer with it. Zero is "there is none", which is an
            // answer like any other.
            self::Ice => $scan->ice_level !== null,
            // Learned only by seeing through a Cloak; a scan whose disguise held never learns there was one.
            self::Cloaked => $scan->cloak_broken === true,
            default => false,
        };
    }

    /**
     * What to SHOW for it, or null when it is not known.
     *
     * This one may reach through a relation, because a screen wants the name
     * of the operating system rather than the id of the skill that gates it.
     */
    public function readFrom(SystemScan $scan): ?string
    {
        if (! $this->isKnownOn($scan)) {
            return null;
        }

        return match ($this) {
            self::Grade => Grade::roman((int) $scan->grade),
            self::OperatingSystem => $scan->operatingSystem()->first()?->title,
            self::Ids => $scan->ids_detection > 0
                ? (string) $scan->ids_detection
                : Text::get('cyberspace.reading.none'),
            // Both halves of one fact, because one roll answered both: what it
            // notices, and how fast it traces once it has.
            self::Tracer => $scan->tracer_detection > 0
                ? Text::get('cyberspace.reading.tracer', [
                    'detection' => (string) $scan->tracer_detection,
                    'rate' => (string) $scan->tracer_rate,
                ])
                : Text::get('cyberspace.reading.none'),
            // Resolved through the catalogue by level, so a renamed piece of
            // ICE reads by its new name in every file anybody is holding —
            // the behaviour renaming an entity already has.
            self::Ice => $scan->ice_level > 0
                ? self::iceWithBounty($scan)
                : Text::get('cyberspace.reading.none'),
            self::Cloaked => Text::get('cyberspace.reading.cloak_seen'),
            default => null,
        };
    }

    /**
     * The guard, and whether its owner answers for it.
     *
     * ONE FACT AND ONE ROLL. A file that knew there was an ICE XII but not
     * whether losing to it would be remembered would be describing half a
     * decision, so the bounty is learned with the guard rather than beside it.
     */
    private static function iceWithBounty(SystemScan $scan): string
    {
        $name = Ice::query()->where('level', $scan->ice_level)->value('name');

        if ($name === null) {
            // The piece was retired out from under an old file. The level is
            // still true about what was there; its name is simply gone.
            return Text::get('cyberspace.reading.unread');
        }

        return $scan->ice_bounty
            ? Text::get('cyberspace.reading.ice_bounty', ['name' => $name])
            : $name;
    }

    public function label(): string
    {
        return Text::get("cyberspace.facts.{$this->value}");
    }

    /**
     * The ones a probe can actually roll for.
     *
     * @return list<self>
     */
    public static function discoverable(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $fact): bool => $fact->isDiscoverable(),
        ));
    }
}
