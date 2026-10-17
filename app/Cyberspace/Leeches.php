<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\Character;
use App\Models\Leech;
use App\Models\Program;
use App\Models\System;
use App\Notifications\Category;
use App\Notifications\Notifier;
use App\Programs\ProgramKind;
use App\Stats\Resolver;
use App\Support\Qb;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of `leeches`, and the one place a fortress's totals are summed.
 *
 * A fortress has no hardware: its compute, RAM and storage are the sum of its LIVE Leechers, a leech being live while
 * its host system still exists and is not locked down (a relocated host is the same row, so it goes on; a burned one is
 * gone). Anything hosted against the totals comes later.
 */
final class Leeches
{
    /** Returned by install() when this player already has a Leecher on the node. */
    public const ALREADY = 'already';

    /**
     * Spend one Leecher from the stash on $host, feeding the chosen fortress (or the first one owned).
     *
     * @return Leech|string|null the row; ALREADY; or null when there is no Leecher to spend or no fortress to feed
     */
    public function install(Character $character, System $host, ?int $fortressId = null): Leech|string|null
    {
        if ($host->type->isFortress()) {
            return null;
        }

        $fortress = System::query()
            ->where('owner_character_id', $character->getKey())
            ->when($fortressId !== null, fn ($q) => $q->whereKey($fortressId))
            ->orderBy('id')
            ->first();

        if ($fortress === null) {
            return null;
        }

        return DB::transaction(function () use ($character, $host, $fortress): Leech|string|null {
            if (Leech::query()->where('character_id', $character->getKey())->where('host_system_id', $host->getKey())->exists()) {
                return self::ALREADY;
            }

            $row = $character->inventory()->loose()
                ->whereHas('item.program', fn ($q) => $q->where('kind', ProgramKind::Leecher->value))
                ->with('item.program')
                ->first();

            $program = $row?->item?->program;

            if ($row === null || $program === null) {
                return null;
            }

            [$resource, $amount] = $this->spec($program);

            if ($resource === null) {
                return null;
            }

            if ($row->quantity > 1) {
                $row->decrement('quantity');
            } else {
                $row->delete();
            }

            return Leech::query()->create([
                'character_id' => $character->getKey(),
                'fortress_system_id' => $fortress->getKey(),
                'host_system_id' => $host->getKey(),
                'resource' => $resource,
                'amount' => $amount,
                'installed_at' => now(),
            ]);
        });
    }

    /**
     * What a fortress has to run on: the sum of its live Leechers, by resource.
     *
     * @return array{compute: float, memory: float, storage: float}
     */
    public function totals(System $fortress): array
    {
        $totals = ['compute' => 0.0, 'memory' => 0.0, 'storage' => 0.0];

        $leeches = Leech::query()->where('fortress_system_id', $fortress->getKey())->get();
        $hosts = System::query()->whereIn('id', $leeches->pluck('host_system_id'))->get()->keyBy('id');

        foreach ($leeches as $leech) {
            $host = $hosts->get($leech->host_system_id);

            if ($host === null || $host->isLockedDown()) {
                continue;
            }

            $totals[$leech->resource] = Qb::of($totals[$leech->resource] + (float) $leech->amount);
        }

        return $totals;
    }

    /**
     * One roll per Leecher against its chance of being found: the host grade's base (config), lowered by the installer's
     * `leech_discovery` skills, and never under the floor. A found one is removed and its owner told. The die is
     * 1 to 10,000 (a chance carried to hundredths of a per cent). Returns how many were removed.
     */
    public function discover(\Closure $roll, bool $dryRun = false): int
    {
        $table = (array) config('cyberspace.fortress.leech_discovery', []);
        $floor = (float) config('cyberspace.fortress.leech_discovery_floor', 0.5);
        $resolver = app(Resolver::class);
        $removed = 0;

        foreach (Leech::query()->get() as $leech) {
            $host = System::query()->find($leech->host_system_id);
            $owner = Character::query()->find($leech->character_id);
            $base = (float) ($table[$host?->grade] ?? reset($table) ?: 10);
            $chance = $owner === null
                ? $base
                : (float) $resolver->resolve('leech_discovery', $base, $owner->modifiers())->value;

            if ($roll(1, 10000) > (int) round(max($floor, $chance) * 100)) {
                continue;
            }

            $removed++;

            if ($dryRun) {
                continue;
            }

            $leech->delete();

            if ($owner !== null) {
                (new Notifier)->send($owner, Category::Cyberspace, 'leech_found', [
                    'system' => $host?->displayName() ?? '',
                ]);
            }
        }

        return $removed;
    }

    /** @return array{0: ?string, 1: float} the single resource a Leecher takes, and how much */
    private function spec(Program $program): array
    {
        return $program->leech_resource === null || (float) $program->leech_amount <= 0
            ? [null, 0.0]
            : [(string) $program->leech_resource, (float) $program->leech_amount];
    }
}
