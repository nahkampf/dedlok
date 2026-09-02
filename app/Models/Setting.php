<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * A single game setting.
 *
 * Settings are read far more often than they are written, and this application
 * talks to a MySQL host over the public internet — so every uncached read is a
 * network round trip. The whole table is cached as one array and invalidated on
 * write; see the note on all() about the limits of that.
 */
#[Fillable(['key', 'value', 'category'])]
class Setting extends Model
{
    protected $table = 'settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    public const CACHE_KEY = 'settings.all';

    /** Backstop expiry, for writes that bypass Eloquent. */
    public const CACHE_TTL_MINUTES = 10;

    /**
     * Every setting, keyed by name.
     *
     * Deliberately NOT called all(): Eloquent already defines Model::all() and
     * it returns a Collection of models. Shadowing it with an array of strings
     * would break every caller that reasonably expects the Eloquent contract.
     *
     * Cached as a single entry rather than one per key: the table is small and
     * read as a whole, so one cache miss is cheaper than N.
     *
     * Invalidation has two layers, for the same reason as SuggestedHandle:
     * model events cover writes through Eloquent, and the TTL is a backstop for
     * query-builder writes and hand-run SQL, which fire no events at all.
     *
     * Shape is key => ['value' => ..., 'category' => ...], so a single cached
     * entry serves both key lookups and category grouping.
     *
     * @return array<string, array{value: string|null, category: string|null}>
     */
    public static function cached(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            fn (): array => self::query()
                ->get(['key', 'value', 'category'])
                ->keyBy('key')
                ->map(fn (self $setting): array => [
                    'value' => $setting->value,
                    'category' => $setting->category,
                ])
                ->all()
        );
    }

    /**
     * Read a setting.
     *
     * A key that exists with a NULL value returns null, NOT the default —
     * `??` would collapse the two, which would make the nullable column
     * pointless. The default fires only when the key is absent entirely.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        $settings = self::cached();

        return array_key_exists($key, $settings) ? $settings[$key]['value'] : $default;
    }

    /**
     * Every setting in a category, as key => value.
     *
     * Passing null returns the ungrouped ones. '' and NULL are treated as the
     * same thing, because "nullable and/or empty" both mean no category and
     * making callers care about which is stored would be a trap.
     *
     * @return array<string, string|null>
     */
    public static function inCategory(?string $category): array
    {
        $wanted = ($category === null || $category === '') ? null : $category;

        $matches = [];

        foreach (self::cached() as $key => $row) {
            $stored = ($row['category'] === null || $row['category'] === '') ? null : $row['category'];

            if ($stored === $wanted) {
                $matches[$key] = $row['value'];
            }
        }

        return $matches;
    }

    /**
     * The categories in use, excluding ungrouped settings.
     *
     * @return list<string>
     */
    public static function categories(): array
    {
        $categories = [];

        foreach (self::cached() as $row) {
            if ($row['category'] !== null && $row['category'] !== '') {
                $categories[$row['category']] = true;
            }
        }

        $names = array_keys($categories);
        sort($names);

        return $names;
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::cached());
    }

    /**
     * Write a setting.
     *
     * $category is only applied when given: put() on an existing setting
     * updates the value and leaves its grouping alone. Pass '' to clear a
     * category, since null here means "don't touch it".
     */
    public static function put(string $key, ?string $value, ?string $category = null): self
    {
        $attributes = ['value' => $value];

        if ($category !== null) {
            $attributes['category'] = $category;
        }

        return self::updateOrCreate(['key' => $key], $attributes);
    }

    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    protected static function booted(): void
    {
        static::saved(fn () => self::flushCache());
        static::deleted(fn () => self::flushCache());
    }
}
