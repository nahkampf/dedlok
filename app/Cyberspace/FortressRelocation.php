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

        if ($since !== null && $since->gt(now()->subHours((int) config('cyberspace.fortress.relocate_hours', 24)))) {
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
            $ids = collect([$fortress->owner_character_id])->merge(
                DB::table('fortress_members')->where('system_id', $fortress->getKey())->pluck('character_id'),
            )->unique();

            // Bookmarks the owner and members hold follow it.
            Bookmark::query()->whereIn('character_id', $ids)->where('address', $old)->update(['address' => $fortress->address]);

            foreach (Character::query()->whereKey($ids)->get() as $who) {
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
}
