<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\SystemScan;
use App\Support\Text;

/**
 * What a read WAS, once it became a thing somebody can carry and sell.
 *
 * A VALUE OBJECT OVER A JSON BLOB, the same shape `App\Cyberspace\ExtractedFile`
 * takes for the same reason: one author, one reader, and a mechanic downstream
 * of it — the market, the hand-in — that is not designed yet, so columns
 * modelled against either would be modelled wrong.
 *
 * ## Every field is a copy, and that is the point
 *
 * The `system_scans` row is spent at export and the machine it describes may
 * relocate, lock down or burn the minute after. A file that reached back
 * through either would be describing nothing. Same call `system_contacts`
 * makes about a sighting, and `notifications` about a stored sentence.
 *
 * ## No position
 *
 * The address travels; x and y do not. An address is meaningless and trading
 * one is expected — that is what `BookmarkKeeper` exists to protect, by only
 * letting a waypoint be set on ground somebody has walked. A file carrying
 * coordinates would sell that rule for creds.
 *
 * ## The facts are stored as COLUMNS, not as rendered text
 *
 * `toScan()` hands them back as an unsaved `SystemScan`, so `ScanFact` renders
 * a sold file exactly as it renders a live one — a renamed piece of ICE reads
 * by its new name in every file anybody is holding. A blob of sentences frozen
 * at export would be a second, staler renderer.
 */
final readonly class ScanFile
{
    public function __construct(
        public string $address,
        public ?string $name,
        public ?int $grade,
        public ?int $osSkillId,
        public ?int $idsDetection,
        public ?int $tracerDetection,
        public ?int $tracerRate,
        public ?int $iceLevel,
        public bool $iceBounty,
        public ?\DateTimeImmutable $madeAt,
        public ?\DateTimeImmutable $expiresAt,
        /**
         * Has its owner been told it went stale?
         *
         * A stale file is not deleted — it sits in the stash until somebody
         * destroys it, which is the player's call — so the sweep that
         * announces one has to remember that it did. A flag on the file
         * rather than a table, because there is exactly one thing that reads
         * it and it is already here. See `scans:stale`.
         */
        public bool $staleAnnounced = false,
    ) {}

    public static function fromScan(SystemScan $scan, ?string $name): self
    {
        return new self(
            address: (string) $scan->address,
            name: $name,
            grade: $scan->grade,
            osSkillId: $scan->os_skill_id,
            idsDetection: $scan->ids_detection,
            tracerDetection: $scan->tracer_detection,
            tracerRate: $scan->tracer_rate,
            iceLevel: $scan->ice_level,
            iceBounty: (bool) $scan->ice_bounty,
            madeAt: now()->toDateTimeImmutable(),
            expiresAt: $scan->expires_at?->toDateTimeImmutable(),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'address' => $this->address,
            'name' => $this->name,
            'grade' => $this->grade,
            'os_skill_id' => $this->osSkillId,
            'ids_detection' => $this->idsDetection,
            'tracer_detection' => $this->tracerDetection,
            'tracer_rate' => $this->tracerRate,
            'ice_level' => $this->iceLevel,
            'ice_bounty' => $this->iceBounty,
            'made_at' => $this->madeAt?->format(DATE_ATOM),
            'expires_at' => $this->expiresAt?->format(DATE_ATOM),
            'stale_announced' => $this->staleAnnounced,
        ];
    }

    /** @param  array<string, mixed>|null  $stored */
    public static function fromArray(?array $stored): ?self
    {
        if ($stored === null || ! isset($stored['address'])) {
            return null;
        }

        $int = static fn (string $key): ?int => isset($stored[$key]) ? (int) $stored[$key] : null;

        return new self(
            address: (string) $stored['address'],
            name: isset($stored['name']) ? (string) $stored['name'] : null,
            grade: $int('grade'),
            osSkillId: $int('os_skill_id'),
            idsDetection: $int('ids_detection'),
            tracerDetection: $int('tracer_detection'),
            tracerRate: $int('tracer_rate'),
            iceLevel: $int('ice_level'),
            iceBounty: (bool) ($stored['ice_bounty'] ?? false),
            madeAt: self::moment($stored['made_at'] ?? null),
            expiresAt: self::moment($stored['expires_at'] ?? null),
            staleAnnounced: (bool) ($stored['stale_announced'] ?? false),
        );
    }

    /**
     * The facts, back in the shape everything that renders a scan already reads.
     *
     * UNSAVED AND NEVER SAVED. It exists to be asked questions — `facts()`,
     * `isComplete()`, `ScanFact::readFrom()` — by screens that should not have
     * to know whether the scan they are drawing is live or sold. `SystemScan`
     * has no writer but `Probe`, and handing one of these to anything that
     * writes would be a second one.
     */
    public function toScan(): SystemScan
    {
        return new SystemScan([
            'address' => $this->address,
            'grade' => $this->grade,
            'os_skill_id' => $this->osSkillId,
            'ids_detection' => $this->idsDetection,
            'tracer_detection' => $this->tracerDetection,
            'tracer_rate' => $this->tracerRate,
            'ice_level' => $this->iceLevel,
            'ice_bounty' => $this->iceBounty,
        ]);
    }

    /**
     * Past its 48 hours.
     *
     * THE CLOCK TRAVELS WITH THE FILE, and nothing sweeps: a stale file is a
     * worthless row somebody destroys, not a row that deletes itself out of an
     * inventory. Without the clock, *throughput is bounded by hardware and by
     * the grid* stops being true and a stockpile becomes a warehouse.
     */
    public function hasExpired(?\DateTimeInterface $now = null): bool
    {
        return $this->expiresAt !== null && $this->expiresAt < ($now ?? now());
    }

    /**
     * The same file, with its owner told.
     *
     * A new instance rather than a mutation: this is a value object, and the
     * row it came off is what actually changes — see `scans:stale`.
     */
    public function announced(): self
    {
        return new self(
            address: $this->address,
            name: $this->name,
            grade: $this->grade,
            osSkillId: $this->osSkillId,
            idsDetection: $this->idsDetection,
            tracerDetection: $this->tracerDetection,
            tracerRate: $this->tracerRate,
            iceLevel: $this->iceLevel,
            iceBounty: $this->iceBounty,
            madeAt: $this->madeAt,
            expiresAt: $this->expiresAt,
            staleAnnounced: true,
        );
    }

    /** What the machine was called when the read finished, or its address if it never said. */
    public function displayName(): string
    {
        return $this->name ?? $this->address;
    }

    /** The name the reading dialog already promised: the address, filed. */
    public function filename(): string
    {
        return Text::get('cyberspace.reading.filename', ['address' => $this->address]);
    }

    private static function moment(mixed $value): ?\DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
