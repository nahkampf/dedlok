<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ArchetypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A starting role, and the perks that come with it.
 *
 * `characters.archetype` stores the id, which is why ids are assigned by hand
 * and never reused: a renumbering would silently re-role every character
 * holding the old number. That used to be a comment in a config file; it is a
 * foreign key now.
 */
#[Fillable(['id', 'slug', 'name', 'role', 'description', 'starting_creds', 'playable'])]
class Archetype extends Model
{
    /** @use HasFactory<ArchetypeFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'int';

    protected function casts(): array
    {
        return ['starting_creds' => 'integer', 'playable' => 'boolean'];
    }

    /**
     * The whole roster, perks included, read once per request.
     *
     * Stat resolution asks for a character's perks every time it runs, and
     * the answer is the same seven rows for everybody. Any write to either
     * table drops this, and the test suite drops it between tests.
     *
     * @var Collection<int, self>|null
     */
    private static ?Collection $roster = null;

    /** @return Collection<int, self> */
    public static function roster(): Collection
    {
        return self::$roster ??= self::query()
            ->with(['perks' => fn ($query) => $query->orderBy('position')->orderBy('id')])
            ->orderBy('id')
            ->get()
            ->keyBy('id');
    }

    /**
     * The archetypes a new character may actually be.
     *
     * A subset of the roster, and the roster is what everything else reads:
     * an archetype that is no longer offered still has to resolve its name and
     * its perks for every character who already chose it. Withdrawing one is
     * `playable = false`, never a delete — `characters.archetype` is a foreign
     * key.
     *
     * @return Collection<int, self>
     */
    public static function playable(): Collection
    {
        return self::roster()->filter(fn (self $archetype): bool => $archetype->playable);
    }

    /** One archetype out of the cached roster, or null. */
    public static function fromRoster(int|string|null $id): ?self
    {
        return $id === null ? null : self::roster()->get((int) $id);
    }

    public static function forgetRoster(): void
    {
        self::$roster = null;
    }

    /**
     * The roster with its loadouts attached, uncached.
     *
     * Deliberately not part of roster(): stat resolution asks for that on
     * nearly every request and needs perks only, so the two extra queries
     * would be paid by every page to serve the two screens that read a
     * loadout — the creation form and the archetype editor.
     *
     * @return Collection<int, self>
     */
    public static function withLoadout(): Collection
    {
        return self::query()
            ->with([
                'perks' => fn ($query) => $query->orderBy('position')->orderBy('id'),
                'startingSkills.skill',
                'startingItems.item.useSkill',
            ])
            ->orderBy('id')
            ->get()
            ->keyBy('id');
    }

    protected static function booted(): void
    {
        static::saved(self::forgetRoster(...));
        static::deleted(self::forgetRoster(...));
    }

    public function perks(): HasMany
    {
        return $this->hasMany(ArchetypePerk::class);
    }

    /**
     * The skills a character of this archetype starts with.
     *
     * Unordered here: the sort a player reads is by the skill's own title,
     * which is a column on another table — the presenters sort the loaded
     * collection rather than making every read a join.
     */
    public function startingSkills(): HasMany
    {
        return $this->hasMany(ArchetypeSkill::class);
    }

    /** The items a character of this archetype starts with. */
    public function startingItems(): HasMany
    {
        return $this->hasMany(ArchetypeItem::class);
    }

    public function characters(): HasMany
    {
        return $this->hasMany(Character::class, 'archetype');
    }
}
