<?php

declare(strict_types=1);

namespace App\Combat;

use App\City\EncounterImage;
use App\Models\CharacterEncounter;
use App\Models\Fight;

/**
 * Which picture a fight shows, and whose credit goes under it. In order:
 *
 *  1. the opponent's OWN picture;
 *  2. otherwise, for a fight an encounter started, that encounter's OWN picture (its realm's stand-in is not used here);
 *  3. otherwise the stand-in for every opponent (`enemies/default.jpg`).
 *
 * So an opponent that has been drawn always wins over the encounter that spawned it, and one that has not still looks like part
 * of the scene rather than like a placeholder. Null when there is nothing at all, and the screen leaves the space out.
 */
final class FightPicture
{
    /** @return array{url: string, credit: ?string, link: ?string}|null */
    public static function for(Fight $fight): ?array
    {
        $npc = $fight->npc;

        if ($npc !== null && ($own = NpcImage::ownUrl($npc)) !== null) {
            return ['url' => $own, 'credit' => $npc->image_credit, 'link' => $npc->image_link];
        }

        // The row stays while the fight is live; once it has gone there is no scene left to borrow from.
        $encounter = $fight->encounter_row_id === null ? null : CharacterEncounter::query()->with('encounter')->find($fight->encounter_row_id)?->encounter;

        if ($encounter !== null && EncounterImage::exists($encounter) && ($url = EncounterImage::url($encounter)) !== null) {
            return ['url' => $url, 'credit' => $encounter->image_credit, 'link' => $encounter->image_link];
        }

        $default = NpcImage::defaultUrl();

        return $default === null ? null : ['url' => $default, 'credit' => null, 'link' => null];
    }
}
