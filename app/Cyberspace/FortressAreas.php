<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\Character;
use App\Models\FortressArea;
use App\Models\System;
use App\Models\SystemContent;

/** The only writer of `fortress_areas`. Owner storage is NULL and cannot be made, changed or deleted. */
final class FortressAreas
{
    public const PERMISSIONS = ['see', 'deposit', 'withdraw'];

    public function create(Character $owner, System $fortress, string $name, bool $forMembers = true): FortressArea|string
    {
        if (FortressAccess::roleOf($owner, $fortress) !== 'owner') {
            return 'not_yours';
        }

        $name = trim($name);

        if ($name === '') {
            return 'bad_name';
        }

        $area = FortressArea::query()->create([
            'system_id' => $fortress->getKey(),
            'name' => mb_substr($name, 0, 40),
            'permissions' => $forMembers ? ['members' => self::PERMISSIONS] : [],
        ]);

        FortressLog::write($fortress, $owner, 'area', "created {$area->name}");

        return $area;
    }

    /** Replace what one audience may do in an area. */
    public function allow(Character $owner, System $fortress, FortressArea $area, string $audience, array $perms): ?string
    {
        if (FortressAccess::roleOf($owner, $fortress) !== 'owner' || (int) $area->system_id !== (int) $fortress->getKey()) {
            return 'not_yours';
        }

        if (! preg_match('/^(members|role:[a-z0-9_]+|member:\d+)$/', $audience)) {
            return 'bad_audience';
        }

        $permissions = (array) $area->permissions;
        $perms = array_values(array_intersect($perms, self::PERMISSIONS));

        if ($perms === []) {
            unset($permissions[$audience]);
        } else {
            $permissions[$audience] = $perms;
        }

        $area->forceFill(['permissions' => $permissions])->save();
        FortressLog::write($fortress, $owner, 'area', "{$area->name}: {$audience} ".implode(',', $perms));

        return null;
    }

    /** Delete an area: what it held goes to the owner's storage. */
    public function delete(Character $owner, System $fortress, FortressArea $area): ?string
    {
        if (FortressAccess::roleOf($owner, $fortress) !== 'owner' || (int) $area->system_id !== (int) $fortress->getKey()) {
            return 'not_yours';
        }

        SystemContent::query()->where('area_id', $area->getKey())->update(['area_id' => null]);
        FortressLog::write($fortress, $owner, 'area', "deleted {$area->name}");
        $area->delete();

        return null;
    }
}
