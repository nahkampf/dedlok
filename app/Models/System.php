<?php

declare(strict_types=1);

namespace App\Models;

use App\Cyberspace\Position;
use App\Cyberspace\SystemType;
use Database\Factories\SystemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
#[Fillable(['address', 'x', 'y', 'type', 'grade', 'name', 'owner_id'])]
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
            'type' => SystemType::class,
        ];
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
