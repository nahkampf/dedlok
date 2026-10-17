<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\Bookmark;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\System;
use App\Notifications\Category;
use App\Notifications\Notifier;
use App\Programs\ProgramKind;
use Illuminate\Support\Facades\DB;

/**
 * A running Relocation program moves its fortress every `relocate_hours`: anybody inside is thrown out, it takes a new
 * position and address, every invited member is told where it is now, and the owner's bookmarks are rewritten. Anybody
 * else's map memory goes stale, which is the point.
 */
final class FortressRelocation
{
    /** The intervals the settings offer, in hours. */
    public const HOURS = [1, 4, 8, 16, 24, 48];

    /** Hours between this fortress's moves: its own setting, else the config default. */
    public static function hoursFor(System $fortress): int
    {
        return (int) ($fortress->relocate_hours ?? config('cyberspace.fortress.relocate_hours', 24));
    }

    /** Is a Relocation program installed (running or not)? The settings are offered while one is. */
    public static function installed(System $fortress): bool
    {
        return InventoryItem::query()->where('fortress_system_id', $fortress->getKey())
            ->whereHas('item.program', fn ($q) => $q->where('kind', ProgramKind::Relocation->value))->exists();
    }

    /** Owner and administrators set the interval and who is told. */
    public function configure(Character $who, System $fortress, int $hours, bool $owner, bool $admins, bool $members): ?string
    {
        if (! FortressAccess::can($who, $fortress, 'host')) {
            return 'not_yours';
        }

        if (! self::installed($fortress)) {
            return 'no_relocation';
        }

        if (! in_array($hours, self::HOURS, true)) {
            return 'bad_interval';
        }

        $fortress->forceFill([
            'relocate_hours' => $hours, 'relocate_tell_owner' => $owner, 'relocate_tell_admins' => $admins, 'relocate_tell_members' => $members,
        ])->save();
        FortressLog::write($fortress, $who, 'settings', "relocation every {$hours}h");

        return null;
    }

    /** Move it if it is due. Returns true when it moved. */
    public function due(System $fortress, ?\Closure $roll = null): bool
    {
        if (! $fortress->type->isFortress() || $fortress->abandoned_at !== null || Fortresses::isFrozen($fortress)) {
            return false;
        }

        $running = InventoryItem::query()->where('fortress_system_id', $fortress->getKey())->where('fortress_running', true)
            ->whereHas('item.program', fn ($q) => $q->where('kind', ProgramKind::Relocation->value))->exists();

        if (! $running) {
            return false;
        }

        $since = $fortress->relocated_at ?? $fortress->created_at;

        if ($since !== null && $since->gt(now()->subHours(self::hoursFor($fortress)))) {
            return false;
        }

        return $this->move($fortress, $roll);
    }

    public function move(System $fortress, ?\Closure $roll = null): bool
    {
        $old = $fortress->address;

        // Whoever is inside is put out first (cancelled runs), then the machine moves.
        Simulator::fromConfig()->cancelEveryRunOn($old);

        if (! RaidLedger::fromConfig($roll)->relocate($fortress)) {
            return false;
        }

        $fortress->refresh();
        $fortress->forceFill(['relocated_at' => now()])->save();

        DB::transaction(function () use ($fortress, $old): void {
            $members = DB::table('fortress_members')->where('system_id', $fortress->getKey())->get();
            $ids = collect([$fortress->owner_character_id])->merge($members->pluck('character_id'))->unique();

            // Bookmarks the owner and members hold follow it, and the owner always has one by the fortress's name.
            Bookmark::query()->whereIn('character_id', $ids)->where('address', $old)->update(['address' => $fortress->address]);
            $this->owner($fortress);

            $admins = $members->filter(fn ($m): bool => in_array('administrator', $this->rolesOf($m), true))->pluck('character_id');
            $tell = collect();
            $fortress->relocate_tell_owner && $tell->push($fortress->owner_character_id);
            $fortress->relocate_tell_admins && $tell = $tell->merge($admins);
            $fortress->relocate_tell_members && $tell = $tell->merge($members->pluck('character_id')->diff($admins));

            foreach (Character::query()->whereKey($tell->unique()->all())->get() as $who) {
                (new Notifier)->send($who, Category::Cyberspace, 'fortress_moved', [
                    'system' => $fortress->displayName(),
                    'address' => $fortress->address,
                    'x' => $fortress->x,
                    'y' => $fortress->y,
                ]);
            }

            FortressLog::write($fortress, null, 'relocate', "to {$fortress->x},{$fortress->y}");
        });

        return true;
    }

    /** The owner's bookmark for the fortress: created if they have none at its new address, and named for it either way. */
    private function owner(System $fortress): void
    {
        if ($fortress->owner_character_id === null) {
            return;
        }

        $label = mb_substr($fortress->displayName(), 0, 48);
        $bookmark = Bookmark::query()->firstOrNew(['character_id' => $fortress->owner_character_id, 'address' => $fortress->address]);
        $bookmark->forceFill(['kind' => BookmarkKind::System, 'label' => $label])->save();
    }

    /** @return list<string> the roles a fortress_members row holds */
    private function rolesOf(object $row): array
    {
        $roles = $row->roles === null ? [] : (array) json_decode((string) $row->roles, true);

        return $roles === [] ? [(string) $row->role] : $roles;
    }
}
