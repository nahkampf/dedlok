<?php

declare(strict_types=1);

namespace App\Characters;

use App\Models\Character;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only writer of `character_flags`: permanent marks on a character.
 *
 * Flags are one way — set, never unset — because a flag records that something
 * happened, and it cannot un-happen. A flag is a lower-case slug.
 */
final class Flags
{
    /** @return bool true when this is news: the flag was not already set */
    public static function set(Character $character, string $flag): bool
    {
        if (preg_match('/^[a-z0-9_]{1,60}$/', $flag) !== 1) {
            throw new InvalidArgumentException("A flag is a lower-case slug of up to 60 characters, got '{$flag}'.");
        }

        return DB::table('character_flags')->insertOrIgnore([
            'character_id' => $character->getKey(),
            'flag' => $flag,
            'set_at' => now(),
        ]) === 1;
    }

    public static function has(Character $character, string $flag): bool
    {
        return DB::table('character_flags')
            ->where('character_id', $character->getKey())
            ->where('flag', $flag)
            ->exists();
    }
}
