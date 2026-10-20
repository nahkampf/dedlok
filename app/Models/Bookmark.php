<?php

declare(strict_types=1);

namespace App\Models;

use App\Cyberspace\BookmarkKind;
use App\Cyberspace\Position;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved way back: either a position or an address, never both.
 *
 * The two kinds carry different promises and this class exists mostly to keep
 * that difference legible. A waypoint always works. A system bookmark is a fact
 * about a machine that was there once, and `resolve()` returning null is the
 * ordinary answer rather than an error — the world moved on.
 *
 * Written only by App\Cyberspace\BookmarkKeeper, which is where the rule that
 * a waypoint must sit on swept ground lives.
 */
#[Fillable(['character_id', 'kind', 'grid_id', 'x', 'y', 'address', 'label'])]
class Bookmark extends Model
{
    protected function casts(): array
    {
        return [
            'kind' => BookmarkKind::class,
            'x' => 'integer',
            'y' => 'integer',
        ];
    }

    /** @return BelongsTo<Character, $this> */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function isWaypoint(): bool
    {
        return $this->kind === BookmarkKind::Waypoint;
    }

    /** Where a waypoint points, or null on a system bookmark. */
    public function position(): ?Position
    {
        return $this->isWaypoint() ? new Position($this->x, $this->y, (int) ($this->grid_id ?? 1)) : null;
    }

    /**
     * The system this points at, or null.
     *
     * Null for a waypoint, which points at a place rather than a machine, and
     * null for a system bookmark whose address has stopped resolving. Both are
     * ordinary; neither is a failure. A caller that needs to tell them apart
     * has `isWaypoint()`.
     */
    public function resolve(): ?System
    {
        return $this->isWaypoint()
            ? null
            : System::query()->firstWhere('address', $this->address);
    }

    /**
     * What to call it on screen when the player named nothing.
     *
     * A waypoint falls back to its coordinates, which is genuinely useful — it
     * is a place, and the numbers are what a place is. A system bookmark falls
     * back to whatever is standing there NOW rather than to the name it wore
     * when it was saved: the bookmark holds no snapshot, and the sighting that
     * does is App\Models\SystemContact.
     */
    public function displayLabel(): ?string
    {
        return $this->label ?? ($this->isWaypoint()
            ? (string) $this->position()
            : $this->resolve()?->name);
    }

    /** @param  Builder<self>  $query */
    public function scopeOfKind(Builder $query, BookmarkKind $kind): void
    {
        $query->where('kind', $kind->value);
    }
}
