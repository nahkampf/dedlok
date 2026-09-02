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
#[Fillable(['id', 'slug', 'name', 'role', 'description'])]
class Archetype extends Model
{
    /** @use HasFactory<ArchetypeFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'int';

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

    /** One archetype out of the cached roster, or null. */
    public static function fromRoster(int|string|null $id): ?self
    {
        return $id === null ? null : self::roster()->get((int) $id);
    }

    public static function forgetRoster(): void
    {
        self::$roster = null;
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

    public function characters(): HasMany
    {
        return $this->hasMany(Character::class, 'archetype');
    }
}
