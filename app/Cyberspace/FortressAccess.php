<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\Character;
use App\Models\FortressArea;
use App\Models\System;
use App\Notifications\Category;
use App\Notifications\Notifier;
use Illuminate\Support\Facades\DB;

/**
 * Who may do what at a Data Fortress, and the only writer of `fortress_members` and `systems.access`.
 *
 * The owner may do everything. A member's role names a list of permissions (`cyberspace.fortress.roles`): `vault`
 * (deposit and take files), `host` (install, start, stop, take down), `alarm` (is told of intrusions).
 */
final class FortressAccess
{
    public const ACCESS = ['private', 'invited', 'public'];

    /** 'owner', the member's first role name, or null for a stranger. */
    public static function roleOf(Character $character, System $fortress): ?string
    {
        if (! $fortress->type->isFortress()) {
            return null;
        }

        if ((int) $fortress->owner_character_id === (int) $character->getKey()) {
            return 'owner';
        }

        return self::rolesOf($character, $fortress)[0] ?? null;
    }

    /** Every role a member holds at this fortress (empty for the owner and for strangers). @return list<string> */
    public static function rolesOf(Character $character, System $fortress): array
    {
        $row = DB::table('fortress_members')->where('system_id', $fortress->getKey())->where('character_id', $character->getKey())->first();

        if ($row === null) {
            return [];
        }

        $roles = $row->roles === null ? [] : (array) json_decode((string) $row->roles, true);

        return array_values(array_unique($roles === [] ? [$row->role] : $roles));
    }

    /**
     * The roles defined for a fortress: the config defaults, overridden by any the owner has defined.
     *
     * @return array<string, list<string>> name => properties
     */
    public static function definitions(System $fortress): array
    {
        $roles = (array) config('cyberspace.fortress.roles', []);

        foreach (DB::table('fortress_roles')->where('system_id', $fortress->getKey())->get() as $row) {
            $roles[$row->name] = (array) json_decode((string) $row->properties, true);
        }

        return $roles;
    }

    /** Does this character carry the property (host, maintain, alarm, audit)? The owner carries every one. */
    public static function can(Character $character, System $fortress, string $permission): bool
    {
        if (self::roleOf($character, $fortress) === 'owner') {
            return true;
        }

        $definitions = self::definitions($fortress);

        foreach (self::rolesOf($character, $fortress) as $role) {
            if (in_array($permission, $definitions[$role] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    /** Is the character admitted at all (the owner, or invited)? */
    public static function admitted(Character $character, System $fortress): bool
    {
        return self::roleOf($character, $fortress) !== null;
    }

    /**
     * May this character do `see`, `deposit` or `withdraw` in the area? A null area is the owner's storage.
     */
    public static function areaCan(Character $character, System $fortress, ?FortressArea $area, string $perm): bool
    {
        if (self::roleOf($character, $fortress) === 'owner') {
            return true;
        }

        if ($area === null || ! self::admitted($character, $fortress)) {
            return false;
        }

        $permissions = (array) $area->permissions;
        $audiences = array_merge(
            ['members'],
            array_map(fn (string $r): string => "role:{$r}", self::rolesOf($character, $fortress)),
            ["member:{$character->getKey()}"],
        );

        foreach ($audiences as $audience) {
            if (in_array($perm, (array) ($permissions[$audience] ?? []), true)) {
                return true;
            }
        }

        return false;
    }

    public function invite(Character $owner, System $fortress, string $handle, string $role = 'member'): ?string
    {
        if (self::roleOf($owner, $fortress) !== 'owner') {
            return 'not_yours';
        }

        if (! array_key_exists($role, self::definitions($fortress))) {
            return 'bad_role';
        }

        $target = Character::query()->where('handle', $handle)->first();

        if ($target === null) {
            return 'no_such_runner';
        }

        if ($target->getKey() === $owner->getKey()) {
            return 'yourself';
        }

        DB::table('fortress_members')->updateOrInsert(
            ['system_id' => $fortress->getKey(), 'character_id' => $target->getKey()],
            ['role' => $role, 'roles' => json_encode([$role]), 'created_at' => now(), 'updated_at' => now()],
        );
        FortressLog::write($fortress, $owner, 'invite', $target->handle.' as '.$role);

        (new Notifier)->send($target, Category::Cyberspace, 'fortress_invite', [
            'owner' => $owner->handle,
            'system' => $fortress->displayName(),
            'address' => $fortress->address,
        ]);

        return null;
    }

    public function revoke(Character $owner, System $fortress, int $characterId): ?string
    {
        if (self::roleOf($owner, $fortress) !== 'owner') {
            return 'not_yours';
        }

        DB::table('fortress_members')->where('system_id', $fortress->getKey())->where('character_id', $characterId)->delete();
        FortressLog::write($fortress, $owner, 'revoke', 'member '.$characterId);

        return null;
    }

    public function setAccess(Character $owner, System $fortress, string $access, ?string $name): ?string
    {
        if (self::roleOf($owner, $fortress) !== 'owner') {
            return 'not_yours';
        }

        if (! in_array($access, self::ACCESS, true)) {
            return 'bad_access';
        }

        FortressLog::write($fortress, $owner, 'settings', "access {$access}");
        $fortress->forceFill(['access' => $access, 'name' => ($name === null || trim($name) === '') ? null : mb_substr(trim($name), 0, 48)])->save();

        return null;
    }

    /** Give a member exactly this list of roles. */
    public function setRoles(Character $owner, System $fortress, int $characterId, array $roles): ?string
    {
        if (self::roleOf($owner, $fortress) !== 'owner') {
            return 'not_yours';
        }

        $known = array_keys(self::definitions($fortress));
        $roles = array_values(array_intersect($roles, $known));

        if ($roles === []) {
            return 'bad_role';
        }

        DB::table('fortress_members')->where('system_id', $fortress->getKey())->where('character_id', $characterId)
            ->update(['role' => $roles[0], 'roles' => json_encode($roles), 'updated_at' => now()]);
        FortressLog::write($fortress, $owner, 'role', "member {$characterId}: ".implode(',', $roles));

        return null;
    }

    /** Define (or change) a role for this fortress. */
    public function defineRole(Character $owner, System $fortress, string $name, array $properties): ?string
    {
        if (self::roleOf($owner, $fortress) !== 'owner') {
            return 'not_yours';
        }

        $name = strtolower(trim($name));

        if (preg_match('/^[a-z][a-z0-9_]{1,29}$/', $name) !== 1) {
            return 'bad_role';
        }

        $properties = array_values(array_intersect($properties, ['host', 'maintain', 'alarm', 'audit']));

        DB::table('fortress_roles')->updateOrInsert(
            ['system_id' => $fortress->getKey(), 'name' => $name],
            ['properties' => json_encode($properties), 'created_at' => now(), 'updated_at' => now()],
        );
        FortressLog::write($fortress, $owner, 'role', "defined {$name}");

        return null;
    }
}
