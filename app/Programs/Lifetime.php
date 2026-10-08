<?php

declare(strict_types=1);

namespace App\Programs;

use App\Models\Character;
use App\Models\InventoryItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A running Script is spent by the clock: `programs.lifetime_seconds` from the row's `started_at`.
 *
 * LAZY, WITH A SWEEP (`programs:expire`), like every clock here. Nothing is stored about the end: it is
 * `started_at` plus the lifetime, so an expired row is simply deleted whenever somebody next reads the
 * running rows (`Character::runningProcesses()`, `RunningPrograms::processesOn()`, starting a program), and
 * the sweep only keeps the table true for a character nobody is looking at. A copy that is stopped early
 * is destroyed by `ProgramRunner`, never put back.
 */
final class Lifetime
{
    public static function endsAt(InventoryItem $row): ?CarbonImmutable
    {
        $seconds = $row->item?->program?->lifetime_seconds;

        if ($seconds === null || $row->started_at === null) {
            return null;
        }

        return CarbonImmutable::instance($row->started_at)->addSeconds($seconds);
    }

    public static function hasEnded(InventoryItem $row): bool
    {
        $end = self::endsAt($row);

        return $end !== null && $end->lte(CarbonImmutable::now());
    }

    /**
     * Delete the rows whose time is up and hand back the rest.
     *
     * @template T of Collection
     *
     * @param  T  $rows  running rows, with `item.program` loaded
     * @return T
     */
    public static function reap(Collection $rows): Collection
    {
        $ended = $rows->filter(fn (InventoryItem $row): bool => self::hasEnded($row));

        if ($ended->isEmpty()) {
            return $rows;
        }

        InventoryItem::query()->whereKey($ended->pluck('id')->all())->delete();

        return $rows->reject(fn (InventoryItem $row): bool => self::hasEnded($row))->values();
    }

    /** Every Script this character has running, spent or not, so a count of what is running is true. */
    public static function reapFor(Character $character): void
    {
        self::reap(
            InventoryItem::query()
                ->where('character_id', $character->getKey())
                ->whereNotNull('running_on_deck_id')
                ->whereHas('item.program', static fn ($query) => $query->whereNotNull('lifetime_seconds'))
                ->with('item.program')
                ->get(),
        );
    }

    /** The sweep: every character's spent Scripts. */
    public static function reapAll(): int
    {
        $rows = InventoryItem::query()
            ->whereNotNull('running_on_deck_id')
            ->whereHas('item.program', static fn ($query) => $query->whereNotNull('lifetime_seconds'))
            ->with('item.program')
            ->get();

        return $rows->count() - self::reap($rows)->count();
    }
}
