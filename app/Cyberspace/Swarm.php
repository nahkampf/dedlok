<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\System;
use App\Models\SystemScan;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The software that scans for you (Wotan: a Utility with `programs.swarms`).
 *
 * A running copy picks a random system on the MAIN grid that its owner has no complete scan of, reads it
 * to completion the way a Surveyor would (`Probe` goes round again by itself until the picture is whole or
 * the file goes stale), and then picks the next. Every copy is its own process, so how many run is the
 * thread ceiling's business and nobody else's.
 *
 * WHAT IT SKIPS: a system with a live complete scan, a system whose scan the owner holds as a file, and a
 * system any of their instruments is already reading. Fortresses are left alone on purpose (they ARE
 * scannable by hand); exchanges, the portal and gateways are not scannable at all.
 *
 * WHAT IT DOES NOT DO: charge fatigue, count towards the scanning achievement or send the inbox message.
 * It is an automatic process, and a thousand automatic reads would otherwise bury all three.
 *
 * Nothing needs the owner's position. Subnets are refused when the program is STARTED
 * (`ProcessRefusal::SwarmSubnet`); the picks are the main grid's alone.
 */
final class Swarm
{
    public function __construct(private readonly Probe $probe) {}

    public static function make(): self
    {
        return new self(app(Probe::class));
    }

    /**
     * The running copies of a swarming program on the deck they are carrying.
     *
     * @return Collection<int, InventoryItem>
     */
    public function instances(Character $character): Collection
    {
        return InventoryItem::query()
            ->where('character_id', $character->getKey())
            ->where('running_on_deck_id', $character->equipped_deck_id ?? 0)
            ->whereHas('item.program', static fn ($query) => $query->where('swarms', true))
            ->get();
    }

    /**
     * Give every idle copy something to read.
     *
     * Called when one starts and whenever the character's reads are settled, so a copy that ran out of
     * systems, or whose read was abandoned or lost its target, is picked up again by the next look.
     */
    public function feed(Character $character, ?CarbonInterface $now = null): void
    {
        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now());

        $busy = SystemScan::query()
            ->where('character_id', $character->getKey())
            ->whereNotNull('pass_ready_at')
            ->whereNotNull('reading_process_id')
            ->pluck('reading_process_id')
            ->all();

        foreach ($this->instances($character) as $instance) {
            if (! in_array($instance->getKey(), $busy, true)) {
                // Nothing left to read for one copy is nothing left for the rest.
                if (! $this->hop($character, $instance, $now)) {
                    return;
                }
            }
        }
    }

    /**
     * Point one copy at its next system, starting the clock at `$at`.
     *
     * `$at` is when the last read FINISHED when a copy is going round again, so the minutes its owner was
     * away are minutes it spent reading. False when there is nothing left to pick.
     */
    public function hop(Character $character, InventoryItem $instance, CarbonImmutable $at): bool
    {
        return DB::transaction(function () use ($character, $instance, $at): bool {
            // Locked, so a sweep and a screen arriving together cannot send one copy to two systems.
            $fresh = InventoryItem::query()->lockForUpdate()->find($instance->getKey());

            if ($fresh === null || $fresh->running_on_deck_id === null) {
                return false;
            }

            $reading = SystemScan::query()
                ->where('reading_process_id', $fresh->getKey())
                ->whereNotNull('pass_ready_at')
                ->exists();

            if ($reading) {
                return true;
            }

            $system = $this->pick($character);

            if ($system === null) {
                return false;
            }

            $this->probe->beginSwarm($character, $system, $fresh, $at);

            return true;
        });
    }

    /** A random system this character has no complete scan of, on the main grid, that nothing of theirs is reading. */
    public function pick(Character $character): ?System
    {
        // Only files that can still count are loaded (a read in progress, or a live unused one): of hundreds a character
        // has made over time most are spent or stale, and `isComplete()` is the costly part. The rule is unchanged.
        $skip = SystemScan::query()
            ->where('character_id', $character->getKey())
            ->where(static fn ($query) => $query
                ->whereNotNull('pass_ready_at')
                ->orWhere(static fn ($live) => $live->whereNull('used_at')->where(static fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))))
            ->get()
            ->filter(static fn (SystemScan $scan): bool => $scan->pass_ready_at !== null || ($scan->isComplete() && ! $scan->hasExpired() && $scan->used_at === null))
            ->pluck('address')
            ->all();

        $held = InventoryItem::query()
            ->where('character_id', $character->getKey())
            ->whereNotNull('scan_file')
            ->get(['id', 'scan_file'])
            ->filter(static fn (InventoryItem $row): bool => ! isset($row->scan_file['expires_at']) || strtotime((string) $row->scan_file['expires_at']) > time())
            ->map(static fn (InventoryItem $row): string => (string) ($row->scan_file['address'] ?? ''))
            ->all();

        $types = array_map(
            static fn (SystemType $type): string => $type->value,
            array_values(array_filter(SystemType::cases(), static fn (SystemType $type): bool => $type->isScannable() && $type !== SystemType::Fortress)),
        );

        // The candidates' addresses, less what is skipped, and one picked at random in PHP: `ORDER BY RAND()` over the whole
        // main grid with a few hundred excluded addresses cost more than everything else here. Retried in case the system
        // moved (its address changed) between the list and the lookup.
        $pool = System::query()->where('grid_id', 1)->whereIn('type', $types)->pluck('address')->all();
        $free = array_values(array_diff($pool, array_merge($skip, $held)));

        for ($try = 0; $try < 3 && $free !== []; $try++) {
            $at = array_rand($free);
            $system = System::query()->where('grid_id', 1)->where('address', $free[$at])->first();

            if ($system !== null) {
                return $system;
            }

            unset($free[$at]);
        }

        return null;
    }
}
