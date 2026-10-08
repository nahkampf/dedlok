<?php

declare(strict_types=1);

namespace App\Characters;

use App\Models\Character;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * THE ONLY WRITER of a character's own picture, `public/images/characters/avatars/<id>.jpg`, in a folder of its own so the sheet can
 * choose it (`Image` kind `characters/avatars`, walking back up to the default) only while the owner is a supporter. The file
 * is never deleted for a lapse: pay again and it is simply shown again.
 *
 * Whatever is uploaded is decoded, centre-cropped to 3:4 and resampled to 300x400, then written as a fresh JPEG: the original
 * bytes (and anything hidden in them, EXIF included) are never kept. Transparency is flattened onto the kit's dark background.
 * Who may upload is the controller's question (supporters), not this class's.
 */
final class Avatar
{
    public const WIDTH = 300;

    public const HEIGHT = 400;

    /** Decoded size is width x height x 4 bytes against a 128M limit, so cap the pixels rather than the file. */
    public const MAX_PIXELS = 16_000_000;

    public static function path(Character $character): string
    {
        return public_path("images/characters/avatars/{$character->id}.jpg");
    }

    public static function exists(Character $character): bool
    {
        return is_file(self::path($character));
    }

    /** @throws RuntimeException when the file is not an image this can read */
    public function store(Character $character, UploadedFile $file): void
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

        // The largest 3:4 window that fits, centred.
        $cropW = min($w, intdiv($h * self::WIDTH, self::HEIGHT));
        $cropH = min($h, intdiv($w * self::HEIGHT, self::WIDTH));

        $out = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagefill($out, 0, 0, (int) imagecolorallocate($out, 0x10, 0x10, 0x10));
        imagecopyresampled($out, $source, 0, 0, intdiv($w - $cropW, 2), intdiv($h - $cropH, 2), self::WIDTH, self::HEIGHT, $cropW, $cropH);

        $path = self::path($character);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        $written = imagejpeg($out, $path, 88);

        if (! $written) {
            throw new RuntimeException('Could not write the picture.');
        }
    }

    public function remove(Character $character): void
    {
        if (self::exists($character)) {
            unlink(self::path($character));
        }
    }
}
