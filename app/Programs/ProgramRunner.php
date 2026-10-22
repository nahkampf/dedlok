<?php

declare(strict_types=1);

namespace App\Programs;

use App\Crafting\Crafting;
use App\Cyberspace\Tracker;
use App\Decks\DeckTotals;
use App\Decryption\DecryptionQueue;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Program;
use App\Models\TrackerSearch;
use App\Stats\Resolver;
use App\Stats\Sources\RunningPrograms;
use App\Support\Qb;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * The only thing that starts or stops a program.
 *
 * `inventory_items.running_on_deck_id` and `started_at` are written here and
 * nowhere else, for the same reason `App\Decks\ModuleFitter` owns fittings: the
 * rules the database cannot reach across a table to check have to live
 * somewhere, and one somewhere is better than four.
 *
 * WHAT RUNNING COSTS. Compute and memory, and only while it runs — that is the
 * difference between the two caps and storage, which is paid by owning the file
 * at all. Both are measured against the deck's RESOLVED totals, so a skill that
 * frees up compute lets a player run one more program without this class
 * knowing that skills exist.
 *
 * WHICH DECK. The equipped one, always. A program runs on the machine being
 * carried, which is what makes unequipping stop everything — the decision
 * CYBERSPACE.md records, and the reason there is no deck argument here.
 *
 * SPLITTING AND MERGING is the fiddly half, and it is fiddly because idle
 * copies stack and running ones do not:
 *
 *   starting  takes one off the pile and gives it a row of its own
 *   stopping  puts it back, MERGING into the pile if one is there
 *
 * That merge is not optional. `stack_key` is unique, and a stopped row whose
 * key matches an existing pile would collide with it — so stopping without
 * merging is an integrity error waiting for the second copy of anything.
 */
final class ProgramRunner
{
    /**
     * Start a program on the character's equipped deck.
     *
     * @param  bool  $duringRun  whether an INTRUSION RUN is beginning. Run-only
     *                           programs are started BY the run and never by a
     *                           player pressing a button, so this defaults to
     *                           false and `RunStarter::start()` passes true for
     *                           the loadout chosen on the briefing. It is the
     *                           only caller that ever may. Jacking into
     *                           cyberspace is not a run and does not pass true.
     */
    public function start(Character $character, InventoryItem $row, bool $duringRun = false): ProcessResult
    {
        // A spent Script still counts as a running copy until somebody removes it.
        Lifetime::reapFor($character);

        $refusal = $this->refusalFor($character, $row, $duringRun);

        if ($refusal !== null) {
            return ProcessResult::refused($refusal);
        }

        $deck = $character->equippedDeck;

        return DB::transaction(function () use ($row, $deck, $character): ProcessResult {
            $started = $this->splitOne($row, (int) $deck->id);

            // A decryptor takes its first waiting file now, not at the next sweep.
            if ($row->item?->program?->kind === ProgramKind::Decryptor) {
                DecryptionQueue::make()->settle($character);
            }

            return ProcessResult::started($started);
        });
    }

    /**
     * Why `start()` would refuse this row, or null when it would not.
     *
     * THE ONE LIST OF CHECKS: `start()` asks it and so does the screen that offers a Run button, so the
     * button is there for exactly what the writer would accept. A read, never a write. `$shared` lets a
     * caller asking about many rows pay for the deck's totals once (`startable()`).
     *
     * @param  array{deck: ?InventoryItem, totals: ?DeckTotals, spent: ?array{qflops: float|int, memory: float|int}}|null  $shared
     */
    public function refusalFor(Character $character, InventoryItem $row, bool $duringRun = false, ?array $shared = null): ?ProcessRefusal
    {
        if ($row->character_id !== $character->id) {
            return ProcessRefusal::NotOwned;
        }

        if ($row->isRunning()) {
            return ProcessRefusal::AlreadyRunning;
        }

        // The one list of checks, so the briefing's loadout and a posted id meet it as well as the stash's Run.
        if ($row->isListed()) {
            return ProcessRefusal::Listed;
        }

        $program = $row->item?->program;

        if ($program === null) {
            return ProcessRefusal::NotAProgram;
        }

        if ($program->kind->neverRunsOnADeck()) {
            return ProcessRefusal::NotRunnable;
        }

        if (! $duringRun && ! $program->background_process) {
            return ProcessRefusal::RunOnly;
        }

        /*
         * ONE ICEBREAKER AT A TIME, whichever ones they are. `icebreaker_damage`
         * sums every running program, so two cheap ones would out-cut a dear
         * one and TWENTY TURNS IS NEVER ENOUGH would stop being true. The
         * upgrade is a bigger icebreaker, never a second one.
         */
        if ($program->kind === ProgramKind::Icebreaker && $this->icebreakerRunning($character)) {
            return ProcessRefusal::OneIcebreaker;
        }

        // One locator at a time, a Pathfinder counting as one: two would be two search boxes answering one question.
        if ($program->kind === ProgramKind::Utility && ($program->locates || $program->walks) && $this->utilityRunning($character, 'locates', 'walks')) {
            return ProcessRefusal::OneLocator;
        }

        // And one Jumper: its own slot, beside a locator.
        if ($program->kind === ProgramKind::Utility && $program->jumps && $this->utilityRunning($character, 'jumps')) {
            return ProcessRefusal::OneJumper;
        }

        /*
         * HOW MANY OF THIS ONE THING ARE ALREADY GOING.
         *
         * Two walls with one message, because a player acts on both the same
         * way: software that cannot multitask is capped at one for ever, and
         * software that can is capped at what this character can keep in their
         * head at once. Checked before the deck, since neither has anything to
         * do with the machine.
         */
        if ($this->threadsRunning($character, (int) $row->item_id) >= $this->threadsAllowed($character, $program)) {
            return ProcessRefusal::NoFreeThread;
        }

        $deck = $shared['deck'] ?? $character->equippedDeck;

        if ($deck === null) {
            return ProcessRefusal::NoDeckEquipped;
        }

        $totals = $shared['totals'] ?? $this->totals($character, $deck);

        if (! $totals->isReady()) {
            return ProcessRefusal::DeckNotReady;
        }

        $spent = $shared['spent'] ?? $this->spentOn($character, (int) $deck->id);

        if (Qb::tenths($totals->qflops) < Qb::tenths($spent['qflops']) + Qb::tenths($program->qflops_cost)) {
            return ProcessRefusal::NotEnoughCompute;
        }

        if (Qb::tenths($totals->quantabits) < Qb::tenths($spent['memory']) + Qb::tenths($program->memory_cost)) {
            return ProcessRefusal::NotEnoughMemory;
        }

        return null;
    }

    /**
     * The programs the equipped deck could start right now: loose, idle, allowed to run in the background, and
     * accepted by every check `start()` makes. The Running modal's list, so what it offers is what a press
     * would do. The deck is totalled once for the whole list.
     *
     * @return Collection<int, InventoryItem>
     */
    public function startable(Character $character): Collection
    {
        Lifetime::reapFor($character);

        $deck = $character->equippedDeck;
        $shared = $deck === null ? null : [
            'deck' => $deck,
            'totals' => $this->totals($character, $deck),
            'spent' => $this->spentOn($character, (int) $deck->id),
        ];

        return InventoryItem::query()
            ->where('character_id', $character->getKey())
            ->loose()
            ->whereHas('item.program', static fn ($q) => $q->where('background_process', true))
            ->with('item.program')
            ->get()
            ->filter(fn (InventoryItem $row): bool => $this->refusalFor($character, $row, false, $shared) === null)
            ->sortBy(fn (InventoryItem $row): string => (string) $row->item?->displayName())
            ->values();
    }

    /** Stop one process, returning the program to its owner's hands. */
    public function stop(Character $character, InventoryItem $row): ProcessResult
    {
        if ($row->character_id !== $character->id) {
            return ProcessResult::refused(ProcessRefusal::NotOwned);
        }

        if (! $row->isRunning()) {
            return ProcessResult::refused(ProcessRefusal::NotRunning);
        }

        return DB::transaction(function () use ($row): ProcessResult {
            $this->putBack($row);

            return ProcessResult::stopped();
        });
    }

    /**
     * Stop everything running on a deck.
     *
     * What unequipping calls, and what destroying a deck calls. Destroys
     * nothing — every program goes back to the stash — which is why nothing
     * confirms it on its own account. Unequipping confirms for a different
     * reason: a decryption in progress loses its cycle.
     */
    public function stopAll(Character $character, InventoryItem $deck): ProcessResult
    {
        // Every change to a deck passes through here, before it is made: bring any build up to date while the deck is
        // still as it was, so the time before the change is credited to the state it was worked in (see Crafting).
        Crafting::make()->settle($character);

        $rows = InventoryItem::query()
            ->where('character_id', $character->id)
            ->where('running_on_deck_id', $deck->id)
            ->get();

        if ($rows->isEmpty()) {
            return ProcessResult::stopped(0);
        }

        return DB::transaction(function () use ($rows): ProcessResult {
            foreach ($rows as $row) {
                $this->putBack($row);
            }

            return ProcessResult::stopped($rows->count());
        });
    }

    /**
     * How many copies of one program this character has going.
     *
     * Counted across every deck rather than only the equipped one, because a
     * thread is a thing the PERSON is doing: the fiction is attention, and a
     * spare deck running something is still something they are keeping track
     * of.
     */
    public function threadsRunning(Character $character, int $itemId): int
    {
        return InventoryItem::query()
            ->where('character_id', $character->getKey())
            ->where('item_id', $itemId)
            ->whereNotNull('running_on_deck_id')
            ->count();
    }

    /** Whether any icebreaker is going, on any deck — the same reach threads have. */
    private function icebreakerRunning(Character $character): bool
    {
        return $this->kindRunning($character, ProgramKind::Icebreaker);
    }

    /** Is a running Utility on the deck with any of these abilities (`locates`, `walks`, `jumps`)? */
    private function utilityRunning(Character $character, string ...$flags): bool
    {
        return InventoryItem::query()
            ->where('character_id', $character->getKey())
            ->whereNotNull('running_on_deck_id')
            ->whereHas('item.program', static function ($q) use ($flags): void {
                $q->where('kind', ProgramKind::Utility->value)->where(static function ($w) use ($flags): void {
                    foreach ($flags as $flag) {
                        $w->orWhere($flag, true);
                    }
                });
            })
            ->exists();
    }

    private function kindRunning(Character $character, ProgramKind ...$kinds): bool
    {
        return InventoryItem::query()
            ->where('character_id', $character->getKey())
            ->whereNotNull('running_on_deck_id')
            ->whereHas('item.program', static fn ($q) => $q->whereIn('kind', array_map(static fn (ProgramKind $k): string => $k->value, $kinds)))
            ->exists();
    }

    /**
     * Stop everything that only runs during an intrusion.
     *
     * What ending a run calls. Nothing run-only may outlive the run that
     * started it, or an icebreaker would sit on the deck spending compute on
     * an open grid it can do nothing to.
     */
    public function stopRunOnly(Character $character): void
    {
        $rows = InventoryItem::query()
            ->where('character_id', $character->getKey())
            ->whereNotNull('running_on_deck_id')
            ->whereHas('item.program', static fn ($q) => $q->where('background_process', false))
            ->get();

        foreach ($rows as $row) {
            $this->putBack($row);
        }
    }

    /**
     * Stop everything running except Scripts (stopping one destroys it).
     *
     * What a hunter's arrival calls before it starts an icebreaker, so a scanner
     * or a daemon holding the deck's compute and memory cannot keep the one
     * program that can fight back from starting.
     */
    public function stopAllButScripts(Character $character): void
    {
        $rows = InventoryItem::query()
            ->where('character_id', $character->getKey())
            ->whereNotNull('running_on_deck_id')
            ->whereHas('item.program', static fn ($q) => $q->whereNull('lifetime_seconds'))
            ->get();

        foreach ($rows as $row) {
            $this->putBack($row);
        }
    }

    /**
     * How many it is allowed at once.
     *
     * ONE, unless the software says it can multitask — a property of the
     * program and not of the person, because an icebreaker fighting two pieces
     * of ICE at once is not a thing skill should be able to buy. For software
     * that can, the ceiling is the `program_threads` stat: one by default, and
     * Multithreading is what raises it.
     */
    public function threadsAllowed(Character $character, Program $program): int
    {
        if (! $program->multithreaded) {
            return 1;
        }

        return app(Resolver::class)
            ->resolve('program_threads', (float) Config::get('programs.threads', 1), $character->modifiers())
            ->toInt();
    }

    /**
     * What the processes on a deck are already spending.
     *
     * @return array{qflops: float, memory: float}
     */
    public function spentOn(Character $character, int $deckId): array
    {
        $qflops = 0.0;
        $memory = 0.0;

        foreach (RunningPrograms::processesOn($character, $deckId) as $row) {
            $program = $row->item?->program;

            if ($program === null) {
                continue;
            }

            $qflops += $program->qflops_cost;
            $memory += $program->memory_cost;

            // A Tracker in the middle of its passes holds more than it does waiting (`programs.tracker`).
            if ($program->tracks && Tracker::isActive((int) $row->getKey())) {
                $qflops += max(0.0, (float) config('programs.tracker.active_qflops') - $program->qflops_cost);
                $memory += max(0.0, (float) config('programs.tracker.active_memory') - $program->memory_cost);
            }
        }

        return ['qflops' => Qb::of($qflops), 'memory' => Qb::of($memory)];
    }

    /** Would the equipped deck still have this much compute and memory free on top of what is running? A read. */
    public function hasRoomFor(Character $character, float $qflops, float $memory): bool
    {
        $deck = $character->equippedDeck;

        if ($deck === null) {
            return false;
        }

        $totals = $this->totals($character, $deck);
        $spent = $this->spentOn($character, (int) $deck->id);

        return Qb::tenths($totals->qflops) >= Qb::tenths($spent['qflops']) + Qb::tenths($qflops)
            && Qb::tenths($totals->quantabits) >= Qb::tenths($spent['memory']) + Qb::tenths($memory);
    }

    /**
     * One copy, running, on a row of its own.
     *
     * A stack of five becomes a stack of four and a single, and the single is
     * what runs. A stack of one is simply taken over — there is nothing left
     * behind to keep.
     *
     * THE SPLIT ROW IS BORN RUNNING, in one save, and that is not a
     * convenience. `stack_key` is derived on every save and is unique; a row
     * created idle and marked running a moment later would carry the pile's key
     * for the length of that moment, and collide with the pile it had just been
     * taken out of. Create it idle and it fails on the FIRST copy, every time.
     */
    private function splitOne(InventoryItem $row, int $deckId): InventoryItem
    {
        if ($row->quantity <= 1) {
            $row->running_on_deck_id = $deckId;
            $row->started_at = CarbonImmutable::now();
            $row->save();

            return $row;
        }

        $row->quantity -= 1;
        $row->save();

        return InventoryItem::create([
            'character_id' => $row->character_id,
            'item_id' => $row->item_id,
            'parent_id' => $row->parent_id,
            'quantity' => 1,
            'running_on_deck_id' => $deckId,
            'started_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * Put a running row back where idle copies live.
     *
     * MERGES rather than simply clearing the columns. Clearing alone would give
     * this row the pile's stack_key, and that index is unique — so the second
     * copy anybody stopped would be an integrity error rather than a stack of
     * two.
     */
    private function putBack(InventoryItem $row): void
    {
        // A decryptor stopping settles the decryption queue first and then closes the cycle — the one place every way
        // of stopping one (stop, stopAll, stopRunOnly, a deck unequipped or destroyed) passes through.
        if ($row->running_on_deck_id !== null && $row->item?->program?->kind === ProgramKind::Decryptor) {
            $owner = Character::query()->find($row->character_id);

            if ($owner !== null) {
                DecryptionQueue::make()->stopped($owner, $row->getKey());
            }
        }

        // A Tracker's filter and clock belong to the running copy and go with it.
        if ($row->item?->program?->tracks) {
            TrackerSearch::query()->where('inventory_item_id', $row->getKey())->delete();
        }

        // A Script is spent by running, so stopping one early destroys it; there is no pile to put it back in.
        if ($row->item?->program?->isTemporary()) {
            $row->delete();

            return;
        }

        $row->running_on_deck_id = null;
        $row->started_at = null;

        $item = $row->item;

        if ($item === null || ! $item->stackable) {
            $row->save();

            return;
        }

        $pile = InventoryItem::query()
            ->where('character_id', $row->character_id)
            ->where('item_id', $row->item_id)
            ->whereNull('running_on_deck_id')
            ->where(fn ($q) => $row->parent_id === null
                ? $q->whereNull('parent_id')
                : $q->where('parent_id', $row->parent_id))
            ->whereKeyNot($row->getKey())
            ->first();

        if ($pile === null) {
            $row->save();

            return;
        }

        $pile->quantity += $row->quantity;
        $pile->save();
        $row->delete();
    }

    private function totals(Character $character, InventoryItem $deck): DeckTotals
    {
        return DeckTotals::of($deck->fittings()->with('module.item.deckModule')->get(), $deck->item)
            ->resolvedWith($character->modifiers(), app(Resolver::class));
    }
}
