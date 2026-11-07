<?php

declare(strict_types=1);

namespace App\City;

use App\Models\Encounter;
use App\Support\Asset;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * THE ONLY WRITER of an encounter's picture: `public/images/encounters/<realm>/<id>.jpg`, the realm being the door's
 * (`cyberspace` for the grid doors, `physical` for the City ones), and `default.jpg` in the same folder when an encounter
 * has none. Found by convention, like every other picture: the editor's upload writes it, deleting the file brings the
 * default back.
 *
 * Whatever is uploaded is decoded, scaled down to at most WIDTH across (never up) and written as a fresh JPEG, so the
 * original bytes, EXIF included, are not kept. Transparency is flattened onto the kit's dark background.
 */
final class EncounterImage
{
    public const WIDTH = 1600;

    /** Decoded size is width x height x 4 bytes against a 128M limit, so cap the pixels rather than the file. */
    public const MAX_PIXELS = 16_000_000;

    public static function realm(EncounterStart $start): string
    {
        return $start->isGrid() ? 'cyberspace' : 'physical';
    }

    public static function path(Encounter $encounter, ?string $realm = null): string
    {
        return public_path('images/encounters/'.($realm ?? self::realm($encounter->starts_on))."/{$encounter->getKey()}.jpg");
    }

    /** Its own picture, else the realm's default, else null (a screen then leaves the space out). */
    public static function url(Encounter $encounter): ?string
    {
        $realm = self::realm($encounter->starts_on);

        foreach (["{$encounter->getKey()}.jpg", 'default.jpg'] as $file) {
            if (is_file(public_path("images/encounters/{$realm}/{$file}"))) {
                return Asset::url("images/encounters/{$realm}/{$file}");
            }
        }

        return null;
    }

    public static function exists(Encounter $encounter): bool
    {
        return is_file(self::path($encounter));
    }

    /** @throws RuntimeException when the file is not an image this can read */
    public function store(Encounter $encounter, UploadedFile $file): void
    {
        $info = @getimagesize($file->getRealPath());

        if ($info === false || $info[0] * $info[1] > self::MAX_PIXELS) {
            throw new RuntimeException('Unreadable or too large.');
        }

        $source = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));

        if ($source === false) {
            throw new RuntimeException('Unreadable.');
        }

        [$w, $h] = [imagesx($source), imagesy($source)];
        $width = min($w, self::WIDTH);
        $height = max(1, (int) round($h * $width / $w));

        $out = imagecreatetruecolor($width, $height);
        imagefill($out, 0, 0, (int) imagecolorallocate($out, 0x10, 0x10, 0x10));
        imagecopyresampled($out, $source, 0, 0, 0, 0, $width, $height, $w, $h);

        $path = self::path($encounter);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        if (! imagejpeg($out, $path, 88)) {
            throw new RuntimeException('Could not write the picture.');
        }
    }

    /** Delete its own picture, so the realm's default shows again. */
    public static function remove(Encounter $encounter): void
    {
        if (self::exists($encounter)) {
            unlink(self::path($encounter));
        }
    }

    /** The door moved the encounter to the other realm: its own picture goes with it. */
    public static function moved(Encounter $encounter, EncounterStart $from): void
    {
        $old = self::path($encounter, self::realm($from));
        $new = self::path($encounter);

        if ($old !== $new && is_file($old)) {
            if (! is_dir(dirname($new))) {
                mkdir(dirname($new), 0775, true);
            }

            rename($old, $new);
        }
    }
}
