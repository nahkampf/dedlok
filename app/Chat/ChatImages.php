<?php

declare(strict_types=1);

namespace App\Chat;

use App\Support\Text;
use Illuminate\Http\UploadedFile;

/**
 * THE ONLY WRITER of `public/images/chat/<token>.jpg`: a picture pasted into a chat.
 *
 * Decoded, shrunk to fit `WIDTH` and written as a fresh JPEG, so the original bytes (EXIF and anything hidden in them)
 * are never kept; transparency is flattened onto the kit's dark background. The token is 16 random hex digits and
 * the line that shows it is `img:<token>` (`Markup`), so a picture is reachable only by someone who was shown the line.
 * ponytail: files are never deleted; sweep the folder by age when it grows.
 */
final class ChatImages
{
    public const WIDTH = 800;

    /** Decoded size is width x height x 4 bytes against a 128M limit, so cap the pixels rather than the file. */
    public const MAX_PIXELS = 16_000_000;

    public static function path(string $token): string
    {
        return public_path("images/chat/{$token}.jpg");
    }

    public static function url(string $token): ?string
    {
        return preg_match('/^[0-9a-f]{16}$/', $token) === 1 && is_file(self::path($token)) ? asset("images/chat/{$token}.jpg") : null;
    }

    /** @throws ChatRefused when the file is not an image this can read */
    public function store(UploadedFile $file): string
    {
        $info = @getimagesize($file->getRealPath());
        $source = $info === false || $info[0] * $info[1] > self::MAX_PIXELS ? false : @imagecreatefromstring((string) file_get_contents($file->getRealPath()));

        if ($source === false) {
            throw new ChatRefused(Text::get('chat.errors.image'));
        }

        [$w, $h] = [imagesx($source), imagesy($source)];
        $width = min($w, self::WIDTH);
        $height = max(1, intdiv($h * $width, $w));

        $out = imagecreatetruecolor($width, $height);
        imagefill($out, 0, 0, (int) imagecolorallocate($out, 0x10, 0x10, 0x10));
        imagecopyresampled($out, $source, 0, 0, 0, 0, $width, $height, $w, $h);

        $token = bin2hex(random_bytes(8));
        $path = self::path($token);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        if (! imagejpeg($out, $path, 85)) {
            throw new ChatRefused(Text::get('chat.errors.image'));
        }

        return $token;
    }
}
