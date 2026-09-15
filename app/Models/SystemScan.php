<?php

declare(strict_types=1);

namespace App\Models;

use App\Cyberspace\Position;
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
 * Written only by App\Cyberspace\Probe.
 */
#[Fillable(['character_id', 'address', 'x', 'y', 'grade', 'os_skill_id', 'passes', 'pass_started_at', 'pass_ready_at', 'reading_process_id', 'scanned_at', 'expires_at'])]
class SystemScan extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'x' => 'integer',
            'y' => 'integer',
            'grade' => 'integer',
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
        return array_values(array_filter([
            $this->grade === null ? 'grade' : null,
            $this->os_skill_id === null ? 'os' : null,
        ]));
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
