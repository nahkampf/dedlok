<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Turns an uploaded picture into the JPEG the game keeps: decoded, scaled down to at most WIDTH across (never up) and written
 * fresh, so the original bytes, EXIF included, are not kept. Transparency is flattened onto the kit's dark background.
 * Shared by every editor that takes a picture (`EncounterImage`, `NpcImage`), so they cannot drift apart.
 */
final class PictureUpload
{
    public const WIDTH = 1600;

    /** Decoded size is width x height x 4 bytes against a 128M limit, so cap the pixels rather than the file. */
    public const MAX_PIXELS = 16_000_000;

    /** @throws RuntimeException when the file is not an image this can read, or cannot be written */
    public static function write(UploadedFile $file, string $path): void
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

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        if (! imagejpeg($out, $path, 88)) {
            throw new RuntimeException('Could not write the picture.');
        }
    }
}
