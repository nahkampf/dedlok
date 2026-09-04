<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pictures of things, found by convention rather than stored on a row.
 *
 * `public/images/<kind>/<id>.jpg`, and `_default.jpg` beside it when that file
 * is not there. No column, no upload, no seeder: dropping a file in makes the
 * picture appear, and deleting it makes the default come back. Content that
 * lives in the database already has enough places to disagree with itself.
 *
 * Two sizes, and they answer different questions:
 *
 *   <id>.jpg        what a card shows. 16:9, and a fixed shape on purpose —
 *                   a grid of cards whose images are each a different height
 *                   is a grid that never lines up.
 *   <id>_full.jpg   what the modal shows. ANY dimensions, because it is the
 *                   picture itself rather than a slot to fill.
 *
 * The full version is optional. Without one there is nothing to open and the
 * card image is simply an image — which is why exists() and full() are separate
 * questions rather than one.
 *
 * A kind may be a PATH — `items/cpu` — because one folder of every item in the
 * game is a folder nobody can find anything in. The picture itself is only ever
 * looked for in the folder its kind names; it is the DEFAULT that walks back up,
 * so `items/_default.jpg` stands in for every type that has not drawn its own
 * yet, and a type that has drawn one overrides it by existing.
 */
final class Image
{
    /** Everything lives under here, one subdirectory per kind of thing. */
    public const ROOT = 'images';

    /** The file used when a thing has no picture of its own. */
    public const DEFAULT = '_default';

    /**
     * The card image for a thing, or null if the kind has no default either.
     *
     * Null rather than a broken URL: a screen that knows there is no picture
     * can leave the space out, and a screen that renders a 404 into an <img>
     * shows a torn-page icon instead.
     */
    public static function card(string $kind, string|int $id): ?string
    {
        return self::resolve($kind, (string) $id, self::DEFAULT);
    }

    /**
     * The full-size image, or null when there is none.
     *
     * Falls back to the default's full version, so a kind can ship one picture
     * that stands in for everything it has not drawn yet — and null when even
     * that is missing, which is the signal not to offer a modal at all.
     */
    public static function full(string $kind, string|int $id): ?string
    {
        return self::resolve($kind, (string) $id.'_full', self::DEFAULT.'_full');
    }

    /**
     * The thing's own picture, then the default of its folder, then the
     * default of each folder above it, as a stamped URL.
     *
     * Stamped through Asset::url() like everything else in public/: there is no
     * bundler, nothing renames a file when its contents change, and a replaced
     * picture that nobody sees for a week is the same bug the CSS had.
     */
    private static function resolve(string $kind, string $own, string $default): ?string
    {
        $folders = self::folders($kind);

        if ($folders === []) {
            return null;
        }

        // The thing's own picture is looked for in its own folder and nowhere
        // else. A file one level up is filed in the wrong place rather than
        // filed loosely, and finding it there would teach the wrong habit.
        $candidates = [[$folders[0], $own]];

        foreach ($folders as $folder) {
            $candidates[] = [$folder, $default];
        }

        foreach ($candidates as [$folder, $name]) {
            $name = self::segment($name);

            if ($name === null) {
                continue;
            }

            $path = self::ROOT."/{$folder}/{$name}.jpg";

            if (is_file(public_path($path))) {
                return Asset::url($path);
            }
        }

        return null;
    }

    /**
     * The folders a kind names, deepest first: `items/cpu` is
     * ['items/cpu', 'items'], and `archetypes` is ['archetypes'].
     *
     * Empty when any part of it is not a path segment, so one bad step
     * refuses the whole path rather than resolving the part before it.
     *
     * @return list<string>
     */
    private static function folders(string $kind): array
    {
        $folders = [];
        $path = '';

        foreach (explode('/', $kind) as $segment) {
            if (self::segment($segment) === null) {
                return [];
            }

            $path = $path === '' ? $segment : "{$path}/{$segment}";
            $folders[] = $path;
        }

        return array_reverse($folders);
    }

    /**
     * One path segment, or null if it is not one.
     *
     * These come from the database rather than from a request, so this is a
     * belt rather than the braces — but a filename built by concatenation is
     * exactly the shape that ends up reading `../../.env` the first time
     * somebody passes it something a player typed.
     */
    private static function segment(string $value): ?string
    {
        return preg_match('/^[A-Za-z0-9_-]+$/', $value) === 1 ? $value : null;
    }
}
