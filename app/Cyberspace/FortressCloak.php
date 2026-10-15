<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\Character;
use App\Models\Entity;
use App\Models\System;

/**
 * A running Cloak makes a Data Fortress present as another system (a type, a grade and an owner the fortress's owner
 * chose) to everybody except the fortress's owner and the people it has admitted. Nothing is rolled: it is a disguise
 * and not a contest.
 *
 * `mask()` returns a COPY of the system carrying the false face, for anything that DISPLAYS a system to somebody
 * (a sweep, a scan's grade, the map's here-panel). Anything that acts on the system reads the real row.
 */
final class FortressCloak
{
    public const TYPES = ['silo', 'relay', 'compute'];

    /** Is the disguise up: a running cloak and a face chosen for it? */
    public static function active(System $fortress): bool
    {
        return $fortress->type->isFortress()
            && $fortress->fortress_cloak !== null
            && $fortress->abandoned_at === null
            && $fortress->cloak_type !== null
            && $fortress->cloak_grade !== null;
    }

    /** What $viewer sees: the false face, or the system itself for somebody with access. */
    public static function mask(System $system, ?Character $viewer): System
    {
        if (! self::active($system) || ($viewer !== null && FortressAccess::admitted($viewer, $system))) {
            return $system;
        }

        $face = $system->replicate();
        $face->forceFill([
            'id' => $system->getKey(),
            'type' => SystemType::from((string) $system->cloak_type),
            'grade' => (int) $system->cloak_grade,
            'owner_id' => $system->cloak_owner_id,
            'owner_character_id' => null,
            'name' => null,
        ]);
        $face->exists = true;
        $face->setRelation('owner', $system->cloak_owner_id === null ? null : Entity::query()->find($system->cloak_owner_id));

        return $face;
    }

    /** The owner sets the face. Only while a cloak is running, as the settings menu only offers it then. */
    public function set(Character $owner, System $fortress, int $grade, string $type, ?int $entityId): ?string
    {
        if (FortressAccess::roleOf($owner, $fortress) !== 'owner') {
            return 'not_yours';
        }

        if ($fortress->fortress_cloak === null) {
            return 'no_cloak';
        }

        if (! in_array($type, self::TYPES, true) || ! Grade::isValid($grade)) {
            return 'bad_cloak';
        }

        if ($entityId !== null && ! Entity::query()->whereKey($entityId)->exists()) {
            return 'bad_cloak';
        }

        $fortress->forceFill(['cloak_grade' => $grade, 'cloak_type' => $type, 'cloak_owner_id' => $entityId])->save();
        FortressLog::write($fortress, $owner, 'settings', "cloak as {$type} grade {$grade}");

        return null;
    }
}
