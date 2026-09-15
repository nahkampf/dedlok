<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\SystemScan;
use App\Support\Text;

/**
 * The things a scan is trying to find out.
 *
 * CYBERSPACE.md lists what a scan catalogues: the operating system, the grade,
 * the IDS, the ICE, the tracer, and any cloaked systems. Two of those exist —
 * the rest are phases away — and this is the one place that says which is
 * which.
 *
 * THE UNBUILT ONES ARE LISTED ANYWAY, and that is the same decision the item
 * card takes with *Show on market* and *Disassemble*: an answer that does not
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
            self::Grade, self::OperatingSystem => true,
            self::Ids, self::Ice, self::Tracer, self::Cloaked => false,
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
            self::Grade => (string) $scan->grade,
            self::OperatingSystem => $scan->operatingSystem()->first()?->title,
            default => null,
        };
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
