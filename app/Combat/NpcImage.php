<?php

declare(strict_types=1);

namespace App\Combat;

use App\Models\Npc;
use App\Support\Asset;
use App\Support\PictureUpload;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * THE ONLY WRITER of an opponent's picture: `public/images/enemies/<id>.jpg`, and `default.jpg` in the same folder when an
 * opponent has none. Found by convention, like every other picture: the editor's upload writes it, deleting the file brings the
 * default back. The decoding is `PictureUpload`'s, shared with the encounter editor.
 */
final class NpcImage
{
    public const FOLDER = 'images/enemies';

    public static function path(Npc $npc): string
    {
        return public_path(self::FOLDER."/{$npc->getKey()}.jpg");
    }

    /** Its own picture, else the folder's default, else null (a screen then leaves the space out). */
    public static function url(Npc $npc): ?string
    {
        return self::ownUrl($npc) ?? self::defaultUrl();
    }

    /** Only a picture of its own: null when it has none, whatever the folder's default is. */
    public static function ownUrl(Npc $npc): ?string
    {
        return self::exists($npc) ? Asset::url(self::FOLDER."/{$npc->getKey()}.jpg") : null;
    }

    /** The folder's stand-in for every opponent that has no picture, if one has been added. */
    public static function defaultUrl(): ?string
    {
        return is_file(public_path(self::FOLDER.'/default.jpg')) ? Asset::url(self::FOLDER.'/default.jpg') : null;
    }

    public static function exists(Npc $npc): bool
    {
        return is_file(self::path($npc));
    }

    /** @throws RuntimeException when the file is not an image this can read */
    public function store(Npc $npc, UploadedFile $file): void
    {
        PictureUpload::write($file, self::path($npc));
    }

    /** Delete its own picture, so the default shows again. */
    public static function remove(Npc $npc): void
    {
        if (self::exists($npc)) {
            unlink(self::path($npc));
        }
    }
}
