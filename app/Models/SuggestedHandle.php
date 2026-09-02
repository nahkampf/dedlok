<?php

declare(strict_types=1);

namespace App\Models;

use App\Rules\ValidHandle;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * A pool of pre-authored handles offered to new players.
 *
 * Legacy table: the primary key is the handle string itself and there are no
 * timestamps, so the Eloquent defaults all have to be turned off explicitly.
 */
#[Fillable(['handle', 'in_use'])]
class SuggestedHandle extends Model
{
    protected $table = 'suggest_hacker_handles';

    protected $primaryKey = 'handle';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['in_use' => 'boolean'];
    }

    /** Cache key for the suggestion pool. */
    public const CACHE_KEY = 'handles.available';

    /** @param  Builder<self>  $query */
    public function scopeAvailable(Builder $query): void
    {
        $query->where('in_use', false);
    }

    /** Backstop expiry. See availableForSuggestion() for why it exists. */
    public const CACHE_TTL_MINUTES = 10;

    /**
     * Every handle a new player may be offered.
     *
     * Two layers of invalidation, and both are needed:
     *
     *  - The model events below clear the cache immediately on any write that
     *    goes through an Eloquent instance. That covers the normal paths.
     *  - The TTL is a backstop, because Eloquent events do NOT fire for
     *    query-builder writes: `SuggestedHandle::where(...)->delete()` and
     *    `->update()` bypass the model entirely, as does a hand-run SQL
     *    statement or a direct edit in a DB client. Without the TTL the cache
     *    would stay stale forever after any of those.
     *
     * Prefer flushCache() over waiting out the TTL if you write to the pool
     * outside Eloquent.
     *
     * The character filter runs in PHP because the rule is a Unicode-property
     * regex MySQL cannot express. It is a safety net: the client picks from
     * this array with no further checks, so a pool entry that would fail
     * registration must never appear in it.
     *
     * @return list<string>
     */
    public static function availableForSuggestion(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            fn (): array => self::query()
                ->available()
                ->orderBy('handle')
                ->pluck('handle')
                ->filter(fn (string $handle): bool => ValidHandle::passes($handle))
                ->values()
                ->all()
        );
    }

    /** Drop the cached pool. Call after any write that bypassed Eloquent. */
    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    protected static function booted(): void
    {
        // Fires only for writes through a model instance — see the note on
        // availableForSuggestion() about query-builder writes.
        static::saved(fn () => self::flushCache());
        static::deleted(fn () => self::flushCache());
    }
}
