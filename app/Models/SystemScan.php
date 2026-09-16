<?php

declare(strict_types=1);

namespace App\Models;

use App\Cyberspace\Position;
use App\Cyberspace\ScanFact;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a character knows about a machine, and for how long.
 *
 * A SIGHTING, LIKE A CONTACT. No pointer at `systems` on purpose: a scan has to
 * outlive the machine it describes, and a scan of something that has since
 * relocated is not an error — it is the reason scan data expires at all.
 *
 * NULL IS THE INTERESTING STATE HERE. A null `grade` means *not found out*,
 * which is the whole subject of this table: a partial picture is the ordinary
 * outcome of scanning something near the limit of what a character can read.
 *
 * THE DEFENCE COLUMNS CARRY A THIRD STATE, and it is the one worth the care:
 * null is *not found out*, and ZERO is *found out, and there is none*. Every
 * machine has a grade, so grade never needed the distinction; not every machine
 * has an intrusion detection, and "this one is clean" is exactly the answer a
 * player spent an instrument and a quarter of an hour to buy.
 *
 * Written only by App\Cyberspace\Probe.
 */
#[Fillable(['character_id', 'address', 'x', 'y', 'grade', 'os_skill_id', 'ids_detection', 'tracer_detection', 'tracer_rate', 'passes', 'pass_started_at', 'pass_ready_at', 'reading_process_id', 'scanned_at', 'expires_at'])]
class SystemScan extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'x' => 'integer',
            'y' => 'integer',
            'grade' => 'integer',
            'ids_detection' => 'integer',
            'tracer_detection' => 'integer',
            'tracer_rate' => 'integer',
            'passes' => 'integer',
            'pass_started_at' => 'datetime',
            'pass_ready_at' => 'datetime',
            'scanned_at' => 'datetime',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    /** The operating system found, or null if that is still unknown. */
    public function operatingSystem(): BelongsTo
    {
        return $this->belongsTo(Skill::class, 'os_skill_id');
    }

    /** The running copy of the scanner doing this read, while one is. */
    public function readingProcess(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'reading_process_id');
    }

    public function position(): Position
    {
        return new Position($this->x, $this->y);
    }

    /**
     * Is there anything left to find out?
     *
     * The list of questions is here rather than in the Probe because a screen
     * asking "is this scan finished" and the probe deciding what to roll for
     * are the same question, and a second copy of the list is how a new
     * property ends up discoverable but never reported.
     *
     * @return list<string>
     */
    public function unknowns(): array
    {
        return array_values(array_map(
            static fn (ScanFact $fact): string => $fact->value,
            array_filter(
                ScanFact::discoverable(),
                fn (ScanFact $fact): bool => ! $fact->isKnownOn($this),
            ),
        ));
    }

    /**
     * Every question a scan asks, answered or not.
     *
     * Includes the ones nothing can find out yet — see App\Cyberspace\ScanFact.
     * A screen that listed only what is implemented would grow rows as features
     * land, which is a screen nobody learns.
     *
     * @return list<array{key: string, label: string, value: ?string, discoverable: bool}>
     */
    public function facts(): array
    {
        return array_map(fn (ScanFact $fact): array => [
            'key' => $fact->value,
            'label' => $fact->label(),
            'value' => $fact->readFrom($this),
            'discoverable' => $fact->isDiscoverable(),
        ], ScanFact::cases());
    }

    /**
     * Is the instrument still reading?
     *
     * A pass whose clock has run out is NOT running: it is waiting to be
     * settled, which is a different state and the one the map resolves on the
     * way in. Nothing may start a second pass in either case.
     */
    public function isRunning(?\DateTimeInterface $now = null): bool
    {
        return $this->pass_ready_at !== null && $this->pass_ready_at->isAfter($now ?? now());
    }

    /** A pass that has finished and not yet been resolved. */
    public function isWaitingToSettle(?\DateTimeInterface $now = null): bool
    {
        return $this->pass_ready_at !== null && ! $this->isRunning($now);
    }

    /**
     * How far through the running pass we are, 0-1, for the kit's `--v`.
     *
     * Zero when nothing is running, which is what an empty bar already means.
     */
    public function progress(?\DateTimeInterface $now = null): float
    {
        if ($this->pass_started_at === null || $this->pass_ready_at === null) {
            return 0.0;
        }

        $total = $this->pass_ready_at->getTimestamp() - $this->pass_started_at->getTimestamp();

        if ($total <= 0) {
            return 1.0;
        }

        $done = ($now ?? now())->getTimestamp() - $this->pass_started_at->getTimestamp();

        return max(0.0, min(1.0, $done / $total));
    }

    public function isComplete(): bool
    {
        return $this->unknowns() === [];
    }

    /**
     * Has this file learned ANYTHING?
     *
     * A started scan and a scan that came to something are different states,
     * and the map draws the second one: a mark that appeared the moment a pass
     * began would be a mark for having pressed a button. Columns only, through
     * `isKnownOn()`, because the map asks this of every file a character holds
     * and a relation would be a query per cell.
     */
    public function hasFindings(): bool
    {
        foreach (ScanFact::discoverable() as $fact) {
            if ($fact->isKnownOn($this)) {
                return true;
            }
        }

        return false;
    }

    /** Spent, or past its expiry. Either way it is no longer worth anything. */
    public function isSpent(): bool
    {
        return $this->used_at !== null || $this->hasExpired();
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** @param  Builder<self>  $query */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('used_at')->where('expires_at', '>', now());
    }
}
