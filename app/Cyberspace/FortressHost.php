<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\Character;
use App\Models\Ice;
use App\Models\InventoryItem;
use App\Models\System;
use App\Programs\ProgramKind;
use App\Support\Qb;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Programs hosted on a Data Fortress, against the totals its Leechers bring in.
 *
 * Storage is paid by being INSTALLED, compute and RAM only while RUNNING, as for a deck. The fortress's own Kernel is
 * implicit: its demand is `cyberspace.fortress.kernel_demand`, it is online while `systems.abandoned_at` is NULL, and it is the
 * last thing to go. When the live totals no longer cover what is running, `settle()` stops things by themselves in
 * tier order (producers, utility, IDS, ICE, then the Kernel), the most demanding first within a tier. Kernel, ICE and IDS
 * restart by themselves when the resources return; producers and utility stay stopped for a person to start.
 */
final class FortressHost
{
    public function __construct(private readonly Leeches $leeches = new Leeches) {}

    /** Install one copy of a hosted program on a fortress the character owns. */
    public function install(Character $character, InventoryItem $row, System $fortress): ?string
    {
        if (! $this->owns($character, $fortress)) {
            return 'not_yours';
        }

        $program = $row->item?->program;

        // The program is the installer's own (an administrator installs what is in THEIR stash).
        if ($row->character_id !== $character->getKey() || $program === null || ! $program->kind->isHosted()) {
            return 'not_hosted';
        }

        return DB::transaction(function () use ($row, $fortress, $program): ?string {
            $this->settle($fortress);

            $used = $this->used($fortress);
            $totals = $this->leeches->totals($fortress);

            if (Qb::tenths($used['storage'] + $this->cost($row, 'storage_size')) > Qb::tenths($totals['storage'])) {
                return 'no_storage';
            }

            $target = $row;

            if ($row->quantity > 1) {
                $row->decrement('quantity');
                $target = new InventoryItem;
                $target->forceFill(['character_id' => $row->character_id, 'item_id' => $row->item_id, 'quantity' => 1]);
            }

            $target->forceFill(['fortress_system_id' => $fortress->getKey(), 'fortress_running' => false, 'fortress_halted' => false, 'parent_id' => null, 'fortress_installed_by' => $row->character_id])->save();
            FortressDefence::installed($target);
            FortressLog::write($fortress, $row->character, 'install', $program->item?->displayName() ?? (string) $row->item_id);

            return null;
        });
    }

    /** Take a program down: back to the stash, merging into a loose pile of the same thing. */
    public function uninstall(Character $character, InventoryItem $row): ?string
    {
        $fortress = System::query()->find($row->fortress_system_id);

        if ($fortress === null || ! $this->owns($character, $fortress)) {
            return 'not_yours';
        }

        DB::transaction(function () use ($row): void {
            FortressDefence::uninstalled($row);
            $row->forceFill(['fortress_system_id' => null, 'fortress_running' => false, 'fortress_halted' => false])->save();

            $pile = InventoryItem::query()
                ->where('character_id', $row->character_id)->where('item_id', $row->item_id)
                ->whereKeyNot($row->getKey())->loose()->whereNotNull('stack_key')->lockForUpdate()->first();

            if ($pile !== null && $row->item?->stackable) {
                $pile->forceFill(['quantity' => (int) $pile->quantity + (int) $row->quantity])->save();
                $row->delete();
            }
        });

        $this->syncDefences($fortress->fresh() ?? $fortress);

        return null;
    }

    public function start(Character $character, InventoryItem $row): ?string
    {
        $fortress = System::query()->find($row->fortress_system_id);

        if ($fortress === null || ! $this->owns($character, $fortress)) {
            return 'not_yours';
        }

        $this->settle($fortress);

        if ($fortress->fresh()->abandoned_at !== null) {
            return 'offline';
        }

        if ($row->fortress_running) {
            return null;
        }

        if (! $this->fits($fortress, $row)) {
            return 'not_enough';
        }

        $this->setRunning($row, true, false);
        $this->syncDefences($fortress->fresh() ?? $fortress);

        return null;
    }

    public function stop(Character $character, InventoryItem $row): ?string
    {
        $fortress = System::query()->find($row->fortress_system_id);

        if ($fortress === null || ! $this->owns($character, $fortress)) {
            return 'not_yours';
        }

        $this->setRunning($row, false, false);
        $this->syncDefences($fortress->fresh() ?? $fortress);

        return null;
    }

    /**
     * Bring a fortress's running programs back inside its live totals, and restart what restarts by itself.
     * Safe to call at any time and as often as you like.
     */
    public function settle(System $fortress): void
    {
        // What ran since the last look is worked BEFORE anything is stopped for a shortage now.
        FortressProduction::make()->settle($fortress->fresh() ?? $fortress);
        $this->settleResources($fortress);
        $this->syncDefences($fortress->fresh() ?? $fortress);
    }

    /** What an intruder meets: the best running IDS and ICE, written to the system row the simulator already reads. */
    private function syncDefences(System $fortress): void
    {
        if (! $fortress->type->isFortress()) {
            return;
        }

        $up = $fortress->abandoned_at === null;
        $running = $up ? $this->hosted($fortress)->filter(fn (InventoryItem $r): bool => $r->fortress_running && $r->defence !== null) : collect();

        $ice = $running->filter(fn ($r): bool => $r->item->program->kind === ProgramKind::FortressIce)
            ->map(fn ($r): array => FortressDefence::effective($r))->sortByDesc('attack')->first();
        $ids = $running->filter(fn ($r): bool => $r->item->program->kind === ProgramKind::FortressIds)
            ->map(fn ($r): int => (int) (FortressDefence::effective($r)['detection'] ?? 0))->max();

        $cloak = $running->filter(fn ($r): bool => $r->item->program->kind === ProgramKind::Cloak)
            ->map(fn ($r): int => (int) (FortressDefence::effective($r)['strength'] ?? 0))->max();

        $template = $ice === null ? null : Ice::query()->where('level', (int) config('cyberspace.fortress.ice_template_level', 1))->value('id');

        $fortress->forceFill([
            'ice_id' => $ice === null ? null : $template,
            'fortress_ice' => $ice === null || $template === null ? null : ['attack' => (int) ($ice['attack'] ?? 0), 'integrity' => (int) ($ice['integrity'] ?? 1)],
            'ids_detection' => (int) ($ids ?? 0),
            'fortress_cloak' => $cloak === null ? null : (int) $cloak,
        ])->save();
    }

    private function settleResources(System $fortress): void
    {
        DB::transaction(function () use ($fortress): void {
            $fortress = System::query()->whereKey($fortress->getKey())->lockForUpdate()->first();

            if ($fortress === null) {
                return;
            }

            $totals = $this->leeches->totals($fortress);
            $running = $this->hosted($fortress)->filter(fn (InventoryItem $r): bool => $r->fortress_running);

            // By reference: arrow functions copy what they capture, and `$running` shrinks as things are stopped.
            $over = function () use (&$running, $fortress, $totals): bool {
                $demand = $this->demand($fortress, $running);

                return $demand['compute'] > $totals['compute'] + 1e-9 || $demand['memory'] > $totals['memory'] + 1e-9;
            };

            while ($over()) {
                $next = $running->sortBy([
                    fn ($a, $b): int => $a->item->program->kind->tier() <=> $b->item->program->kind->tier(),
                    fn ($a, $b): int => $this->weight($b) <=> $this->weight($a),
                ])->first();

                if ($next === null) {
                    // Nothing left to stop but the Kernel: the fortress goes offline.
                    $fortress->forceFill(['abandoned_at' => $fortress->abandoned_at ?? now()])->save();

                    return;
                }

                $halted = $next->item->program->kind->tier() >= ProgramKind::FortressIds->tier();
                $this->setRunning($next, false, $halted);
                $running = $running->reject(fn ($r): bool => $r->getKey() === $next->getKey());
            }

            // Back online, and the self-restarting tiers come back in order: Kernel, then ICE, then IDS.
            $kernel = $this->kernelDemand();
            $free = function () use (&$running, $fortress, $totals): array {
                $demand = $this->demand($fortress, $running);

                return ['compute' => $totals['compute'] - $demand['compute'], 'memory' => $totals['memory'] - $demand['memory']];
            };

            if ($fortress->abandoned_at !== null) {
                if ($free()['compute'] + 1e-9 >= 0 && $free()['memory'] + 1e-9 >= 0 && $totals['compute'] >= $kernel['compute'] && $totals['memory'] >= $kernel['memory']) {
                    // Saved: the Kernel runs again, as an otherwise empty fortress to rebuild.
                    $fortress->forceFill(['abandoned_at' => null, 'frozen_until' => null, 'kernel_integrity' => null])->save();
                } else {
                    return;
                }
            }

            foreach ([ProgramKind::FortressIce, ProgramKind::FortressIds] as $kind) {
                foreach ($this->hosted($fortress)->filter(fn ($r): bool => $r->fortress_halted && ! $r->fortress_running && $r->item->program->kind === $kind) as $row) {
                    $p = $row->item->program;

                    if ($this->cost($row, 'qflops_cost') <= $free()['compute'] + 1e-9 && $this->cost($row, 'memory_cost') <= $free()['memory'] + 1e-9) {
                        $this->setRunning($row, true, false);
                        $running = $running->push($row);
                    }
                }
            }
        });
    }

    /** Is the fortress online (its Kernel running)? */
    public function online(System $fortress): bool
    {
        return $fortress->abandoned_at === null;
    }

    /**
     * What is being used, by resource, with the Kernel (when online) counted.
     *
     * @return array{compute: float, memory: float, storage: float}
     */
    public function used(System $fortress): array
    {
        $running = $this->hosted($fortress)->filter(fn (InventoryItem $r): bool => $r->fortress_running);
        $demand = $this->demand($fortress, $running);
        $storage = (float) $this->hosted($fortress)->sum(fn (InventoryItem $r): float => $this->cost($r, 'storage_size'))
            + $this->kernelDemand()['storage']
            + FortressVault::stored($fortress);

        return ['compute' => $demand['compute'], 'memory' => $demand['memory'], 'storage' => Qb::of($storage)];
    }

    /** @return Collection<int, InventoryItem> */
    public function hosted(System $fortress): Collection
    {
        return InventoryItem::query()->where('fortress_system_id', $fortress->getKey())->with('item.program')->get()
            ->filter(fn (InventoryItem $r): bool => $r->item?->program !== null)->values();
    }

    /**
     * The Kernel's own demand is part of what is used while it is online.
     *
     * @param  Collection<int, InventoryItem>  $running
     * @return array{compute: float, memory: float}
     */
    private function demand(System $fortress, Collection $running): array
    {
        $kernel = $this->kernelDemand();

        return [
            'compute' => Qb::of($kernel['compute'] + (float) $running->sum(fn ($r): float => $this->cost($r, 'qflops_cost'))),
            'memory' => Qb::of($kernel['memory'] + (float) $running->sum(fn ($r): float => $this->cost($r, 'memory_cost'))),
        ];
    }

    /** @return array{compute: float, memory: float, storage: float} */
    private function kernelDemand(): array
    {
        $k = (array) config('cyberspace.fortress.kernel_demand', []);

        return ['compute' => (float) ($k['compute'] ?? 0), 'memory' => (float) ($k['memory'] ?? 0), 'storage' => (float) ($k['storage'] ?? 0)];
    }

    private function weight(InventoryItem $row): float
    {
        return $this->cost($row, 'qflops_cost') + $this->cost($row, 'memory_cost');
    }

    /** A hosted copy's demand: the program's number, less what maintenance has cut from this copy. */
    private function cost(InventoryItem $row, string $column): float
    {
        $cut = min(100.0, max(0.0, (float) ($row->defence['cut'] ?? 0)));

        return Qb::of((float) $row->item->program->{$column} * (1 - $cut / 100));
    }

    private function fits(System $fortress, InventoryItem $row): bool
    {
        $totals = $this->leeches->totals($fortress);
        $demand = $this->demand($fortress, $this->hosted($fortress)->filter(fn ($r): bool => $r->fortress_running));

        return Qb::tenths($demand['compute'] + $this->cost($row, 'qflops_cost')) <= Qb::tenths($totals['compute'])
            && Qb::tenths($demand['memory'] + $this->cost($row, 'memory_cost')) <= Qb::tenths($totals['memory']);
    }

    private function setRunning(InventoryItem $row, bool $running, bool $halted): ?string
    {
        $row->forceFill(['fortress_running' => $running, 'fortress_halted' => $halted])->save();

        return null;
    }

    private function owns(Character $character, System $fortress): bool
    {
        return FortressAccess::can($character, $fortress, 'host');
    }
}
