<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A public asset URL, stamped with the file's own modification time.
 *
 * There is no bundler here and none is wanted, so nothing rewrites a filename
 * when its contents change — and Apache sends no Cache-Control for
 * public/assets, which leaves browsers free to hold a copy indefinitely
 * without ever asking whether it is still current. That is invisible while a
 * file is stable and then costs a whole afternoon: the markup arrives new, the
 * behaviour that drives it is a week old, and the screen simply does nothing.
 *
 * The stamp is the file's mtime rather than a hand-kept version, because a
 * number someone has to remember to bump is a number that is wrong exactly
 * when it matters. A missing file falls back to the bare URL: a 404 that says
 * so beats one dressed up with a query string.
 */
class Asset
{
    public static function url(string $path): string
    {
        $stamp = @filemtime(public_path($path));

        return asset($path).($stamp === false ? '' : '?v='.$stamp);
    }
}
