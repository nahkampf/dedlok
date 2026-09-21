<?php

declare(strict_types=1);

namespace App\Models;

use App\Cyberspace\Position;
use App\Cyberspace\SystemType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Config;

/**
 * The last thing a character saw at a position: a name, an address, a type,
 * and when they looked.
 *
 * A SIGHTING, NOT A POINTER. There is no `system_id` and no foreign key to
 * `systems`, deliberately — a contact has to outlive the machine it describes,
 * because a machine that relocated or burned is the case the record exists to
 * be wrong about. The map a player sees is their memory, not a live feed.
 *
 * That makes "is this still true?" a real question with three answers, and
 * `standingIs()` is where it is asked: something else is there now, nothing is
 * there now, or the same machine is still there. Only the third is current.
 * None of the three is an error.
 *
 * Overwritten in place by looking again — one row per position per character,
 * which is what "the LAST thing they saw" means. `seen_at` is the only clock,
 * so there is no `updated_at` free to say a different moment.
 */
#[Fillable(['character_id', 'x', 'y', 'address', 'name', 'type', 'owner', 'locked_down_until', 'seen_at', 'first_seen_at'])]
class SystemContact extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'x' => 'integer',
            'y' => 'integer',
            'type' => SystemType::class,
            'locked_down_until' => 'immutable_datetime',
            'seen_at' => 'immutable_datetime',
            'first_seen_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Character, $this> */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    /** Where this was seen. The position is permanent; the sighting is not. */
    public function position(): Position
    {
        return new Position($this->x, $this->y);
    }

    /**
     * Is the machine standing here now the one this remembers?
     *
     * The address is what answers it, because an address dies with the system
     * that wore it: a relocated machine comes back with a new one and a burned
     * one takes its own out of existence. Null — nothing standing there at all
     * — is an ordinary answer and means the same thing as a stranger.
     */
    public function standingIs(?System $standing): bool
    {
        return $standing !== null && $standing->address === $this->address;
    }

    /**
     * Has this machine only just appeared to them?
     *
     * Counted from FIRST sighting, never from the last: looking at something
     * again does not make it newer. A contact taken before the column existed
     * has no first sighting recorded and is treated as old, which is the safe
     * way round — a map that lit up everything the day this shipped would have
     * told nobody anything.
     */
    public function isNew(?int $minutes = null): bool
    {
        if ($this->first_seen_at === null) {
            return false;
        }

        $minutes ??= (int) Config::get('cyberspace.new_for_minutes', 10);

        return $this->first_seen_at->isAfter(now()->subMinutes($minutes));
    }

    /** @param  Builder<self>  $query */
    public function scopeAt(Builder $query, Position $position): void
    {
        $query->where('x', $position->x)->where('y', $position->y);
    }

    /**
     * Everything remembered inside a square window, edges included.
     *
     * Clipped by arithmetic rather than by the caller, like System::within():
     * asking for x between -3 and 3 is harmless, and a caller that had to clip
     * would be a caller that knows how big the grid is.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithin(Builder $query, Position $centre, int $range): void
    {
        $query->whereBetween('x', [$centre->x - $range, $centre->x + $range])
            ->whereBetween('y', [$centre->y - $range, $centre->y + $range]);
    }

    /**
     * Was it shut when this was seen?
     *
     * A MEMORY AND NOT A READING. The window recorded here may have closed
     * since — in which case the machine has relocated and this contact is
     * describing somewhere it no longer is, which is the ordinary way a
     * sighting goes wrong rather than an error. The map draws it because a
     * machine seen rebooting is worth walking back to check on.
     */
    public function wasLockedDown(): bool
    {
        return $this->locked_down_until !== null;
    }
}
