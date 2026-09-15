<?php

declare(strict_types=1);

namespace App\Programs;

use App\Decks\DeckTotals;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Program;
use App\Stats\Resolver;
use App\Stats\Sources\RunningPrograms;
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
     * @param  bool  $duringRun  whether an INTRUSION RUN is in progress. Run-only
     *                           programs are started BY the run and never by a
     *                           player pressing a button, so this defaults to
     *                           false and the simulator passes true. The
     *                           simulator is the only caller that ever may.
     *                           Jacking into cyberspace is not a run — that is
     *                           built now, and it does not pass true.
     */
    public function start(Character $character, InventoryItem $row, bool $duringRun = false): ProcessResult
    {
        if ($row->character_id !== $character->id) {
            return ProcessResult::refused(ProcessRefusal::NotOwned);
        }

        if ($row->isRunning()) {
            return ProcessResult::refused(ProcessRefusal::AlreadyRunning);
        }

        $program = $row->item?->program;

        if ($program === null) {
            return ProcessResult::refused(ProcessRefusal::NotAProgram);
        }

        if (! $duringRun && ! $program->background_process) {
            return ProcessResult::refused(ProcessRefusal::RunOnly);
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
            return ProcessResult::refused(ProcessRefusal::NoFreeThread);
        }

        $deck = $character->equippedDeck;

        if ($deck === null) {
            return ProcessResult::refused(ProcessRefusal::NoDeckEquipped);
        }

        $totals = $this->totals($character, $deck);

        if (! $totals->isReady()) {
            return ProcessResult::refused(ProcessRefusal::DeckNotReady);
        }

        $spent = $this->spentOn($character, (int) $deck->id);

        if ($totals->qflops < $spent['qflops'] + $program->qflops_cost) {
            return ProcessResult::refused(ProcessRefusal::NotEnoughCompute);
        }

        if ($totals->quantabits < $spent['memory'] + $program->memory_cost) {
            return ProcessResult::refused(ProcessRefusal::NotEnoughMemory);
        }

        return DB::transaction(function () use ($row, $deck): ProcessResult {
            return ProcessResult::started($this->splitOne($row, (int) $deck->id));
        });
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
     * @return array{qflops: int, memory: int}
     */
    public function spentOn(Character $character, int $deckId): array
    {
        $qflops = 0;
        $memory = 0;

        foreach (RunningPrograms::processesOn($character, $deckId) as $row) {
            $program = $row->item?->program;

            if ($program === null) {
                continue;
            }

            $qflops += $program->qflops_cost;
            $memory += $program->memory_cost;
        }

        return ['qflops' => $qflops, 'memory' => $memory];
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
            $row->started_at = now();
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
            'started_at' => now(),
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
        return DeckTotals::of($deck->fittings()->with('module.item.deckModule')->get())
            ->resolvedWith($character->modifiers(), app(Resolver::class));
    }
}
