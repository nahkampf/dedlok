<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\Character;
use App\Models\Entity;
use App\Models\Ice;
use App\Models\Run;
use App\Models\Skill;
use App\Models\System;
use App\Models\SystemScan;

/**
 * A running Cloak makes a Data Fortress present as another system to everybody except the fortress's owner, the people it
 * has admitted and a scan that saw through it. The owner chooses the face: a type, a grade and an owner, and the ICE level,
 * IDS, tracer and operating system a scan reports.
 *
 * **It is a contest, once per scan.** A scan rolls its reading against the Cloak's stealth (`systems.fortress_cloak`, the
 * strongest running Cloak, the scale of `scan.difficulty`) the first time it resolves, and the answer is kept on the file
 * (`system_scans.cloak_broken`). Seen through, the file's facts are the real ones and the sweep and the map show the real
 * fortress while the file lasts; held, every fact the scan finds is the false face and the scan never learns there was one.
 *
 * `mask()` returns a COPY of the system carrying the false face, for anything that DISPLAYS a system to somebody
 * (a sweep, a scan's facts, the map's here-panel). Anything that acts on the system reads the real row, so a run is fought
 * against the real ICE and IDS whatever they were called.
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

    /** The stealth a scan must overcome. */
    public static function stealth(System $fortress): float
    {
        return (float) $fortress->fortress_cloak;
    }

    /** Does $viewer see the fortress as it is: admitted, or holding a live scan that overcame the Cloak? */
    public static function seesThrough(System $system, ?Character $viewer): bool
    {
        if ($viewer === null) {
            return false;
        }

        return FortressAccess::admitted($viewer, $system)
            || SystemScan::query()
                ->where('character_id', $viewer->getKey())->where('x', $system->x)->where('y', $system->y)
                ->where('cloak_broken', true)->where('expires_at', '>', now())->exists();
    }

    /** What $viewer sees: the false face, or the system itself for somebody who sees through it. */
    public static function mask(System $system, ?Character $viewer): System
    {
        if (! self::active($system) || self::seesThrough($system, $viewer)) {
            return $system;
        }

        return self::face($system);
    }

    /** The false face as a display copy, whoever is looking. */
    public static function face(System $system): System
    {
        $face = $system->replicate();
        $face->forceFill([
            'id' => $system->getKey(),
            'type' => SystemType::from((string) $system->cloak_type),
            'grade' => (int) $system->cloak_grade,
            'owner_id' => $system->cloak_owner_id,
            'owner_character_id' => null,
            'name' => null,
            'ice_bounty' => false,
        ]);

        // An unset detail shows through as it is; a set one (zero is "none") replaces it.
        if ($system->cloak_ice_level !== null) {
            $face->ice_id = $system->cloak_ice_level === 0 ? null : Ice::query()->where('level', $system->cloak_ice_level)->value('id');
        }

        foreach (['ids_detection' => 'cloak_ids_detection', 'tracer_detection' => 'cloak_tracer_detection', 'tracer_rate' => 'cloak_tracer_rate', 'os_skill_id' => 'cloak_os_skill_id'] as $real => $fake) {
            if ($system->{$fake} !== null) {
                $face->{$real} = $system->{$fake};
            }
        }

        $face->exists = true;
        $face->setRelation('owner', $system->cloak_owner_id === null ? null : Entity::query()->find($system->cloak_owner_id));

        return $face;
    }

    /** The name a run's ICE is shown under: the false face's ICE for somebody the Cloak still fools, else the real one. */
    public static function iceNameFor(Run $run): string
    {
        $system = $run->system();
        $fake = $system?->cloak_ice_level;

        if ($system === null || $fake === null || $fake === 0 || ! self::active($system) || self::seesThrough($system, $run->character)) {
            return (string) $run->ice?->name;
        }

        return (string) (Ice::query()->where('level', $fake)->value('name') ?? $run->ice?->name);
    }

    /**
     * The owner sets the face. Only while a cloak is running, as the settings menu only offers it then.
     *
     * @param  array{ice?: int, ids?: int, tracer_detection?: int, tracer_rate?: int, os?: ?int}  $details
     */
    public function set(Character $owner, System $fortress, int $grade, string $type, ?int $entityId, array $details = []): ?string
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

        $ice = $details['ice'] ?? null;
        $os = $details['os'] ?? null;

        if (($ice !== null && $ice > 0 && ! Ice::query()->where('level', $ice)->exists())
            || ($os !== null && ! Skill::query()->whereKey($os)->where('category', 'Operating Systems')->exists())) {
            return 'bad_cloak';
        }

        $fortress->forceFill([
            'cloak_grade' => $grade, 'cloak_type' => $type, 'cloak_owner_id' => $entityId,
            'cloak_ice_level' => $ice, 'cloak_ids_detection' => $details['ids'] ?? null,
            'cloak_tracer_detection' => $details['tracer_detection'] ?? null, 'cloak_tracer_rate' => $details['tracer_rate'] ?? null,
            'cloak_os_skill_id' => $os,
        ])->save();
        FortressLog::write($fortress, $owner, 'settings', "cloak as {$type} grade {$grade}");

        return null;
    }
}
