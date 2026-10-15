<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\Character;
use App\Models\System;
use Illuminate\Support\Facades\DB;

/** The only writer of `fortress_log`: one immutable row per thing done at a fortress. */
final class FortressLog
{
    public static function write(System $fortress, ?Character $who, string $action, ?string $detail = null): void
    {
        DB::table('fortress_log')->insert([
            'system_id' => $fortress->getKey(),
            'character_id' => $who?->getKey(),
            'action' => $action,
            'detail' => $detail === null ? null : mb_substr($detail, 0, 255),
            'created_at' => now(),
        ]);
    }
}
