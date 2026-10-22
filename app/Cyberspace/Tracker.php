<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\System;
use App\Models\TrackerSearch;
use App\Notifications\Category;
use App\Notifications\Notifier;
use App\Programs\ProgramKind;
use App\Programs\ProgramRunner;
use App\Support\Text;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * The Tracker: the ONLY writer of `tracker_searches`, and the only thing that rolls a pass.
 *
 * A Utility with `programs.tracks`. It runs on the deck like any program (and costs its row's idle figures), is given a
 * filter (owner, type, grade; at least one), and is then RELEASED: a pass a minute (`programs.tracker.pass_seconds`)
 * picks one random place on the grid and looks at it and the eight around it for a system that fits. While it runs
 * passes it holds `programs.tracker.active_*` instead of its idle cost, which `ProgramRunner::spentOn()` reads from
 * `isActive()`. The first fit ends it: the program is stopped and the character gets a message naming the address.
 *
 * Settling is LAZY and swept (`tracker:settle`): whole passes since `pass_started_at` are rolled whenever somebody
 * looks, capped by `max_catchup`, and the clock moves by the passes credited.
 *
 * ponytail: the deck is checked at release only. A deck that later shrinks keeps the Tracker going on what it had.
 */
final class Tracker
{
    public function __construct(private readonly ?\Closure $die = null) {}

    /** The running Tracker, if there is one. */
    public function program(Character $character): ?InventoryItem
    {
        return $character->runningProcesses()->first(
            static fn ($row): bool => $row->item?->program?->kind === ProgramKind::Utility && $row->item->program->tracks,
        );
    }

    public function searchOf(InventoryItem $row): ?TrackerSearch
    {
        return TrackerSearch::query()->where('inventory_item_id', $row->getKey())->first();
    }

    /** Is this running copy in the middle of its passes (and so holding the active cost)? */
    public static function isActive(int $inventoryItemId): bool
    {
        return TrackerSearch::query()->where('inventory_item_id', $inventoryItemId)->whereNotNull('pass_started_at')->exists();
    }

    /** Choose what to look for. Changing it takes the Tracker back to waiting. Null on success, else what to tell them. */
    public function setFilter(Character $character, ?int $ownerId, ?string $type, ?int $grade): ?string
    {
        $row = $this->program($character);

        if ($row === null) {
            return Text::get('cyberspace.tracker.not_running');
        }

        if ($ownerId === null && $type === null && $grade === null) {
            return Text::get('cyberspace.tracker.no_filter');
        }

        TrackerSearch::query()->updateOrCreate(
            ['inventory_item_id' => $row->getKey()],
            ['character_id' => $character->getKey(), 'owner_id' => $ownerId, 'type' => $type, 'grade' => $grade, 'pass_started_at' => null],
        );

        return null;
    }

    /** Start the passes. Null on success, else what to tell them. */
    public function release(Character $character, ProgramRunner $runner): ?string
    {
        return DB::transaction(function () use ($character, $runner): ?string {
            $row = $this->program($character);
            $search = $row === null ? null : TrackerSearch::query()->where('inventory_item_id', $row->getKey())->lockForUpdate()->first();

            if ($row === null || $search === null || ! $search->hasFilter()) {
                return Text::get($row === null ? 'cyberspace.tracker.not_running' : 'cyberspace.tracker.no_filter');
            }

            if ($search->isReleased()) {
                return null;
            }

            $program = $row->item->program;
            $extraQ = max(0.0, (float) Config::get('programs.tracker.active_qflops') - $program->qflops_cost);
            $extraM = max(0.0, (float) Config::get('programs.tracker.active_memory') - $program->memory_cost);

            if (! $runner->hasRoomFor($character, $extraQ, $extraM)) {
                return Text::get('cyberspace.tracker.no_room');
            }

            $search->forceFill(['pass_started_at' => CarbonImmutable::now()])->save();

            return null;
        });
    }

    /**
     * Roll the passes that have gone by. True when the Tracker found something (and has stopped).
     */
    public function settle(Character $character, ?CarbonInterface $now = null, ProgramRunner $runner = new ProgramRunner): bool
    {
        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now());
        $found = null;

        DB::transaction(function () use ($character, $now, $runner, &$found): void {
            $search = TrackerSearch::query()->where('character_id', $character->getKey())->whereNotNull('pass_started_at')->lockForUpdate()->first();

            if ($search === null) {
                return;
            }

            $seconds = max(1, (int) Config::get('programs.tracker.pass_seconds'));
            $passes = min((int) Config::get('programs.tracker.max_catchup'), (int) floor($search->pass_started_at->diffInSeconds($now, false) / $seconds));

            if ($passes < 1) {
                return;
            }

            $grid = Grid::of($character);

            for ($i = 0; $i < $passes && $found === null; $i++) {
                $found = $this->pass($grid, $search);
            }

            if ($found === null) {
                $search->forceFill(['pass_started_at' => $search->pass_started_at->addSeconds($passes * $seconds)])->save();

                return;
            }

            // Stopping puts the program back and takes the search with it (`ProgramRunner::putBack()`).
            $row = InventoryItem::query()->find($search->inventory_item_id);
            $row !== null && $runner->stop($character, $row);
        });

        if ($found !== null) {
            (new Notifier)->send($character, Category::Cyberspace, 'tracker_found', ['address' => $found->address], ['address' => $found->address]);
        }

        return $found !== null;
    }

    /** One pass: a random place and the eight around it. The first system that fits, or null. */
    private function pass(Grid $grid, TrackerSearch $search): ?System
    {
        $size = $grid->size();
        $roll = $this->die ?? random_int(...);
        $x = $roll(0, $size - 1);
        $y = $roll(0, $size - 1);

        return System::query()
            ->where('grid_id', $grid->id())
            ->whereBetween('x', [$x - 1, $x + 1])->whereBetween('y', [$y - 1, $y + 1])
            ->where(static fn ($open) => $open->whereNull('locked_down_until')->orWhere('locked_down_until', '<=', now()))
            ->when($search->owner_id !== null, static fn ($q) => $q->where('owner_id', $search->owner_id))
            ->when($search->type !== null, static fn ($q) => $q->where('type', $search->type))
            // An Exchange's grade is the desk's own, not the system's (which stays at its floor).
            ->when($search->grade !== null, static fn ($q) => $q->where(static fn ($g) => $g
                ->where(static fn ($s) => $s->where('type', '!=', SystemType::Exchange->value)->where('grade', $search->grade))
                ->orWhereHas('exchange', static fn ($e) => $e->where('grade', $search->grade))))
            ->orderBy('address')
            ->first();
    }
}
