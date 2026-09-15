<?php

declare(strict_types=1);

namespace App\Models;

use App\Cyberspace\Position;
use App\Cyberspace\SystemNamer;
use App\Cyberspace\SystemType;
use Database\Factories\SystemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A machine standing somewhere on the grid.
 *
 * Its address and its coordinates are attributes it CURRENTLY HAS. Both change
 * when it relocates and the row survives that with its id intact, which is what
 * lets a backdoor, an owner or a grudge outlive the address it was made against.
 *
 * Nothing outside App\Cyberspace\Grid should query this table by position. The
 * world is materialised rows today and a generator with override rows at full
 * size, and Grid is the one place that knows which — see CYBERSPACE.md, *How
 * the grid is stored*.
 */
#[Fillable(['address', 'x', 'y', 'type', 'grade', 'name', 'designation', 'owner_id', 'os_skill_id'])]
class System extends Model
{
    /** @use HasFactory<SystemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'x' => 'integer',
            'y' => 'integer',
            'grade' => 'integer',
            'raids' => 'integer',
            'designation' => 'integer',
            'type' => SystemType::class,
        ];
    }

    /**
     * What it calls itself.
     *
     * Composed from the type, the owner and the designation unless the row
     * carries an authored name — see App\Cyberspace\SystemNamer, which is the
     * one place that decision is made. Reads the owner relation, so eager-load
     * it when naming more than one machine at a time.
     */
    public function displayName(): string
    {
        return SystemNamer::fromConfig()->for($this);
    }

    /** Where it is standing right now. */
    public function position(): Position
    {
        return new Position($this->x, $this->y);
    }

    public function isPortal(): bool
    {
        return $this->type === SystemType::Portal;
    }

    /**
     * Who runs this machine, or null.
     *
     * Null is the portal and only the portal: it is the one system nobody
     * owns, and inventing a company to run it would be a fact nobody asked
     * for. An owner survives relocation the way the row does — a company does
     * not stop owning a machine because the machine moved.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Corp::class, 'owner_id');
    }

    /**
     * What it runs, as the skill that gates it.
     *
     * A foreign key rather than a word, because the operating system IS a
     * skill gate: the name, the description and the book all live on that row,
     * and a varchar here would be a second spelling of it. NULL is the portal
     * — and a world seeded before the skills existed, which is a content gap
     * rather than a secret.
     */
    public function operatingSystem(): BelongsTo
    {
        return $this->belongsTo(Skill::class, 'os_skill_id');
    }

    /**
     * What it is holding.
     *
     * Its own inventory model rather than a stash with a different owner:
     * raided rather than carried, safe from what ICE does to a person, and
     * restocked on a clock. See App\Models\SystemContent.
     */
    public function contents(): HasMany
    {
        return $this->hasMany(SystemContent::class);
    }

    /** @param  Builder<self>  $query */
    public function scopeAt(Builder $query, Position $position): void
    {
        $query->where('x', $position->x)->where('y', $position->y);
    }

    /**
     * Everything inside a square window, edges included.
     *
     * The window is clipped by arithmetic rather than by the query: asking for
     * x between -3 and 3 is harmless, and a caller that had to clip first would
     * be a caller that knows how big the grid is.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithin(Builder $query, Position $centre, int $range): void
    {
        $query->whereBetween('x', [$centre->x - $range, $centre->x + $range])
            ->whereBetween('y', [$centre->y - $range, $centre->y + $range]);
    }
}
