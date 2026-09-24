<?php

declare(strict_types=1);

namespace App\Models;

use App\Entities\EntityCategory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An organisation in the world: a corporation, a government branch, a military
 * command or something on the fringe.
 *
 * Distinct from the eight hardware manufacturers in WORLDBUILDING.md, which
 * brand items rather than run nodes — who BUILT a thing and who RUNS it are
 * different questions and this answers the second.
 *
 * Entities arrive by migration, like the archetype roster, because their ids
 * are written into thousands of system rows, and one that still runs machines
 * cannot be deleted.
 *
 * **`interactable` is the whole difference between scenery and somebody you
 * can work for.** A flavour entity owns machines, names them and appears on a
 * card; it has no missions, no standing and no store. The flag defaults to
 * false, the call `archetypes.playable` makes.
 */
#[Fillable(['name', 'category', 'interactable', 'business', 'description'])]
class Entity extends Model
{
    protected function casts(): array
    {
        return [
            'category' => EntityCategory::class,
            'interactable' => 'boolean',
        ];
    }

    /** Everyone a player can actually deal with. */
    public function scopeInteractable(Builder $query): void
    {
        $query->where('interactable', true);
    }

    /**
     * What this entity will hand over for loyalty.
     *
     * A shelf, spanning desk grades rather than belonging to one desk — see
     * App\Models\EntityOffer. Authored in the admin; read by the Exchange.
     *
     * @return HasMany<EntityOffer, $this>
     */
    public function offers(): HasMany
    {
        return $this->hasMany(EntityOffer::class);
    }

    /** @return HasMany<System, $this> */
    public function systems(): HasMany
    {
        return $this->hasMany(System::class, 'owner_id');
    }
}
