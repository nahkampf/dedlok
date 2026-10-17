<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\Character;
use App\Programs\ProgramKind;

/**
 * The gate on Jump buttons: a Utility that jumps (`programs.jumps`) running on the equipped deck, and a body on the grid.
 * Gates the buttons beside addresses only; the map's own typed Jump box is free.
 */
final class Jumper
{
    /** May this character use a Jump button now? Asked once per request and character, since a page can show dozens. */
    public static function available(?Character $character): bool
    {
        if ($character === null) {
            return false;
        }

        $key = 'jumper.'.$character->getKey();
        $attributes = request()->attributes;

        if (! $attributes->has($key)) {
            $attributes->set($key, $character->isJackedIn() && $character->runningProcesses()->contains(
                static fn ($row): bool => $row->item?->program?->kind === ProgramKind::Utility && $row->item->program->jumps,
            ));
        }

        return (bool) $attributes->get($key);
    }
}
