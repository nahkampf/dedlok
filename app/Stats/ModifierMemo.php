<?php

declare(strict_types=1);

namespace App\Stats;

/**
 * `Character::modifiers()` remembered for the length of ONE web request.
 *
 * Building the list costs a dozen queries, and a page asks for it from the chrome, the sheet, the stat readers and the
 * screen itself: dozens of times for the same answer. It is off everywhere but a web request (a scheduled command lives
 * for minutes and `now()` moves under it), switched on and off by `MemoiseModifiers`.
 *
 * THE WAY IT STAYS TRUE: anything that writes to the database empties it (`AppServiceProvider` listens to every
 * statement, so a query-builder `update()` is caught as surely as a model save), and so does a transaction ending either
 * way. It is keyed by character, archetype and equipped deck, so a different in-memory state is a different entry; the
 * list for an arbitrary moment (`$asAt`) or with keys left out (`$without`) is never remembered.
 */
final class ModifierMemo
{
    private static bool $active = false;

    /** @var array<string, list<Modifier>> */
    private static array $store = [];

    public static function enable(): void
    {
        self::$active = true;
        self::$store = [];
    }

    public static function disable(): void
    {
        self::$active = false;
        self::$store = [];
    }

    public static function active(): bool
    {
        return self::$active;
    }

    public static function flush(): void
    {
        self::$store = [];
    }

    /** @return list<Modifier>|null */
    public static function get(string $key): ?array
    {
        return self::$store[$key] ?? null;
    }

    /** @param list<Modifier> $modifiers */
    public static function put(string $key, array $modifiers): void
    {
        self::$store[$key] = $modifiers;
    }
}
