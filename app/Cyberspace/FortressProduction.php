<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Decks\Storage;
use App\Models\Character;
use App\Models\Design;
use App\Models\FortressArea;
use App\Models\FortressJob;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\System;
use App\Models\SystemContent;
use App\Programs\ProgramKind;
use App\Stats\Resolver;
use App\Support\Qb;
use Illuminate\Support\Facades\DB;

/**
 * What a Data Fortress's running Producers make, in CYCLES.
 *
 * A cycle is `cyberspace.fortress.produce.cycle_minutes` (360) as the `production_cycle_minutes` stat resolves it for whoever
 * installed the producer (so a skill or anything else can target it), divided by what the running Boosters add. Each
 * producer carries its own minutes towards its next cycle; a cycle that has come due is worked once per settle.
 *
 * Per cycle a producer is authored (`programs.produces`) to give:
 * - **credits**: a number between `credits_min` and `credits_max`, rolled (captured transactions) into the wallet;
 * - **components**: `components` files toward a components job in the queue;
 * - **programs**: `programs` units of work toward a program job (a Design's craft time in hours is its work).
 *
 * Components and programs are one queue of at most five jobs, first in first out. A program job spends its Design's
 * components out of the fortress when work starts and waits (a cycle yields nothing) without enough; a finished product
 * waits without room, so storage is the ceiling. Compute and RAM are drawn by being a running program: a short fortress
 * stops producers first and nothing is worked while they are stopped. Output lands in `systems.output_area_id`, NULL being
 * the owner's storage.
 */
final class FortressProduction
{
    public const MAX_QUEUE = 5;

    /** @param  \Closure(int, int): int  $roll */
    public function __construct(private readonly \Closure $roll) {}

    public static function make(): self
    {
        return new self(static fn (int $min, int $max): int => random_int($min, $max));
    }

    public function enqueue(Character $by, System $fortress, string $kind, ?int $designItemId = null, int $count = 1): ?string
    {
        if (! FortressAccess::can($by, $fortress, 'host')) {
            return 'not_yours';
        }

        if (FortressJob::query()->where('system_id', $fortress->getKey())->count() >= self::MAX_QUEUE) {
            return 'queue_full';
        }

        if ($kind === 'components') {
            $work = (float) max(1, min(20, $count));
        } elseif ($kind === 'program') {
            $design = Design::query()->where('item_id', (int) $designItemId)->first();
            $held = SystemContent::query()->where('system_id', $fortress->getKey())->where('kind', ContentKind::Item->value)->where('item_id', (int) $designItemId)->exists();

            if ($design === null || ! $held) {
                return 'no_design';
            }

            $work = max(0.01, (float) $design->craft_seconds / 3600);
        } else {
            return 'bad_job';
        }

        FortressJob::query()->create([
            'system_id' => $fortress->getKey(), 'kind' => $kind, 'design_item_id' => $designItemId,
            'work' => $work, 'queued_by' => $by->getKey(),
        ]);
        FortressLog::write($fortress, $by, 'produce', "queued {$kind}");

        return null;
    }

    public function cancel(Character $by, System $fortress, FortressJob $job): ?string
    {
        if (! FortressAccess::can($by, $fortress, 'host') || (int) $job->system_id !== (int) $fortress->getKey()) {
            return 'not_yours';
        }

        // Components already spent are not given back: the same rule cancelling a hand build keeps.
        $job->delete();

        return null;
    }

    public function setOutput(Character $owner, System $fortress, ?int $areaId): ?string
    {
        if (FortressAccess::roleOf($owner, $fortress) !== 'owner') {
            return 'not_yours';
        }

        if ($areaId !== null && ! FortressArea::query()->where('system_id', $fortress->getKey())->whereKey($areaId)->exists()) {
            return 'not_yours';
        }

        $fortress->forceFill(['output_area_id' => $areaId])->save();
        FortressLog::write($fortress, $owner, 'settings', "output area {$areaId}");

        return null;
    }

    /** Work every cycle that has come due since the last settle. Safe to call as often as you like. */
    public function settle(System $fortress): void
    {
        if (! $fortress->type->isFortress() || $fortress->abandoned_at !== null || Fortresses::isFrozen($fortress)) {
            $fortress->forceFill(['production_at' => now()])->save();

            return;
        }

        $since = $fortress->production_at ?? now();
        $minutes = min(24 * 7 * 60.0, max(0.0, $since->diffInSeconds(now(), false) / 60));
        $fortress->forceFill(['production_at' => now()])->save();

        if ($minutes <= 0) {
            return;
        }

        DB::transaction(function () use ($fortress, $minutes): void {
            $running = InventoryItem::query()->where('fortress_system_id', $fortress->getKey())->where('fortress_running', true)
                ->with('item.program')->get();

            $boost = $running->filter(fn ($r): bool => $r->item?->program?->kind === ProgramKind::Booster)
                ->sum(fn ($r): float => (float) ($r->item->program->produces['boost'] ?? 0));

            foreach ($running->filter(fn ($r): bool => $r->item?->program?->kind === ProgramKind::Producer) as $row) {
                $cycle = $this->cycleMinutes($row, $boost);
                $defence = (array) $row->defence;
                $carry = (float) ($defence['carry_m'] ?? 0) + $minutes;
                $cycles = (int) floor($carry / $cycle);
                $defence['carry_m'] = round($carry - $cycles * $cycle, 3);
                $row->forceFill(['defence' => $defence])->save();

                // Bounded: a long absence is not a year of cycles, and the loop is the cost.
                for ($i = 0; $i < min($cycles, 200); $i++) {
                    $this->cycle($fortress, $row);
                }
            }
        });
    }

    /** Minutes in one of this producer's cycles: the resolved stat for its installer, quickened by the boosters. */
    public function cycleMinutes(InventoryItem $row, float $boostPercent = 0.0): float
    {
        $installer = $row->fortress_installed_by === null ? null : Character::query()->find($row->fortress_installed_by);
        $base = (float) config('cyberspace.fortress.produce.cycle_minutes', 360);
        $hours = $installer === null
            ? $base
            : (float) app(Resolver::class)->resolve('production_cycle_minutes', $base, $installer->modifiers())->value;

        return max(1.0, $hours / (1 + max(0.0, $boostPercent) / 100));
    }

    /** One finished cycle of one producer. */
    private function cycle(System $fortress, InventoryItem $row): void
    {
        $produces = (array) $row->item->program->produces;

        if (isset($produces['credits_min']) || isset($produces['credits_max'])) {
            $min = (int) ($produces['credits_min'] ?? 0);
            $max = max($min, (int) ($produces['credits_max'] ?? $min));
            $amount = ($this->roll)($min, $max);

            if ($amount > 0) {
                $fortress->forceFill(['wallet' => (int) $fortress->wallet + $amount])->save();
                FortressLog::write($fortress, null, 'captured', (string) $amount);
            }
        }

        $job = FortressJob::query()->where('system_id', $fortress->getKey())->orderBy('id')->get()
            ->first(fn (FortressJob $j): bool => isset($produces[$j->kind === 'program' ? 'programs' : 'components']));

        if ($job === null) {
            return;
        }

        if ($job->kind === 'components') {
            $files = (int) floor((float) $produces['components']);
            $make = (int) min($files, ceil($job->work - $job->progress));

            for ($i = 0; $i < $make; $i++) {
                if (! $this->deliver($fortress, $job, $row)) {
                    break;
                }

                $job->progress = $job->progress + 1;
            }

            $job->progress + 1e-9 >= $job->work ? $job->delete() : $job->save();

            return;
        }

        if (! $job->started) {
            if (! $this->startProgram($fortress, $job)) {
                return; // not enough components: this cycle yields nothing
            }

            $job->started = true;
        }

        if ($job->progress + 1e-9 < $job->work) {
            $job->progress = min($job->work, $job->progress + (float) $produces['programs']);
        }

        if ($job->progress + 1e-9 >= $job->work) {
            $this->deliver($fortress, $job, $row) ? $job->delete() : $job->save(); // no room: it waits, finished

            return;
        }

        $job->save();
    }

    /** Spend the design's components out of the fortress, or report there are not enough. */
    private function startProgram(System $fortress, FortressJob $job): bool
    {
        $design = Design::query()->where('item_id', $job->design_item_id)->with('components')->first();

        if ($design === null) {
            $job->delete();

            return false;
        }

        $have = fn (int $itemId) => SystemContent::query()->where('system_id', $fortress->getKey())
            ->where('kind', ContentKind::Item->value)->where('item_id', $itemId)->orderBy('id')->get();

        foreach ($design->components as $line) {
            if ($have((int) $line->component_item_id)->sum('quantity') < (int) $line->quantity) {
                return false;
            }
        }

        foreach ($design->components as $line) {
            $left = (int) $line->quantity;

            foreach ($have((int) $line->component_item_id) as $content) {
                $take = min($left, (int) $content->quantity);
                $take >= (int) $content->quantity ? $content->delete() : $content->forceFill(['quantity' => $content->quantity - $take])->save();
                $left -= $take;

                if ($left <= 0) {
                    break;
                }
            }
        }

        if ($design->single_use) {
            $copy = SystemContent::query()->where('system_id', $fortress->getKey())->where('kind', ContentKind::Item->value)
                ->where('item_id', $design->item_id)->orderBy('id')->first();

            if ($copy !== null) {
                (int) $copy->quantity > 1 ? $copy->forceFill(['quantity' => $copy->quantity - 1])->save() : $copy->delete();
            }
        }

        return true;
    }

    /** Put the product in the output area, or say there is no room. */
    private function deliver(System $fortress, FortressJob $job, InventoryItem $producer): bool
    {
        $item = $job->kind === 'components' ? $this->randomComponent() : Design::query()->where('item_id', $job->design_item_id)->first()?->product;

        if ($item === null) {
            return true; // nothing to make: the job is simply done
        }

        $size = (float) Storage::sizeOf($item);
        $free = (new Leeches)->totals($fortress)['storage'] - (new FortressHost)->used($fortress)['storage'];

        if (Qb::tenths($size) > Qb::tenths($free)) {
            return false;
        }

        $meta = null;

        if ($item->program !== null && in_array($item->program->kind, [ProgramKind::FortressIce, ProgramKind::FortressIds, ProgramKind::Cloak], true)) {
            $installer = $producer->fortress_installed_by === null ? null : Character::query()->find($producer->fortress_installed_by);
            $meta = ['defence' => FortressDefence::numbersFor($installer, $item)];
        }

        SystemContent::query()->create([
            'system_id' => $fortress->getKey(),
            'kind' => ContentKind::Item->value,
            'item_id' => $item->getKey(),
            'quantity' => 1,
            'area_id' => $fortress->output_area_id,
            'deposited_by' => $job->queued_by,
            'meta' => $meta,
        ]);

        FortressLog::write($fortress, null, 'produced', $item->displayName());

        return true;
    }

    /** A component by the configured grade's weights. */
    private function randomComponent(): ?Item
    {
        $grade = (int) config('cyberspace.fortress.produce.component_grade', 3);
        $weights = [];

        foreach ((array) config('cyberspace.decryption.components', []) as $slug => $def) {
            $w = (int) ($def['weights'][$grade] ?? 0);

            if ($w > 0) {
                $weights[$slug] = $w;
            }
        }

        if ($weights === []) {
            return null;
        }

        $draw = ($this->roll)(1, array_sum($weights));

        foreach ($weights as $slug => $w) {
            $draw -= $w;

            if ($draw <= 0) {
                return Item::query()->with('program')->where('slug', $slug)->first();
            }
        }

        return null;
    }
}
