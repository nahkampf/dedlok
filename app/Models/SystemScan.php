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
#[Fillable(['character_id', 'address', 'x', 'y', 'grade', 'os_skill_id', 'passes', 'scanned_at', 'expires_at'])]
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
