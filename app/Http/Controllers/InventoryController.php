<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Cyberspace\ReadinessWatch;
use App\Decks\FittingRefusal;
use App\Decks\ModuleFitter;
use App\Drugs\DrugTaker;
use App\Inventory\ItemPlacer;
use App\Inventory\SkillInjector;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Programs\ProgramRunner;
use App\Support\Text;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The two inventories, and the one thing you can currently do from them.
 *
 * There is no "use item" op set here on purpose: injecting is the only use
 * that exists, and inventing a generic dispatcher for a single case would be
 * the kind of framework the item ops are meant to avoid. When a second op
 * arrives, this grows a match on $item->use_op and nothing else changes.
 */
class InventoryController extends Controller
{
    public function index(Request $request): View
    {
        $character = $this->character($request);

        $physical = $character->inventoryIn(Item::CONTAINER_PHYSICAL);

        return view('inventory.index', [
            'character' => $character,
            'physical' => $physical,
            'data' => $character->inventoryIn(Item::CONTAINER_DATA),
            // Somewhere to put things. Already loaded with their contents, so
            // the views can ask what fits without a query per row.
            'containers' => $physical->filter(fn ($row) => $row->item->isContainer())->values(),
        ]);
    }

    public function inject(Request $request, InventoryItem $entry, SkillInjector $injector): RedirectResponse
    {
        $character = $this->character($request);

        // Someone else's row is not a refusal to explain, it is a row that
        // does not exist as far as this player is concerned. The injector
        // checks ownership again; this is the HTTP-shaped half.
        abort_unless($entry->character_id === $character->id, 404);

        $skillTitle = $entry->item?->useSkill?->title;

        $result = $injector->inject($character, $entry);

        if (! $result->succeeded()) {
            return back()->with('error', $result->refusal->message());
        }

        return back()->with('status', Text::get('inventory.injected', ['skill' => $skillTitle]));
    }

    /**
     * Take one.
     *
     * The HTTP-shaped half of `App\Drugs\DrugTaker`, which is the only door
     * between an item and somebody's blood. Nothing is confirmed: a dose is
     * one copy of a thing that is meant to be used, and the mechanic that
     * makes taking one dangerous is the overdose rather than the click.
     *
     * AN OVERDOSE IS SAID OUT LOUD as an error even though the dose worked —
     * losing nerve tissue is the one outcome nobody should have to infer from
     * a number moving somewhere else.
     */
    public function take(Request $request, InventoryItem $entry, DrugTaker $taker): RedirectResponse
    {
        $character = $this->character($request);

        abort_unless($entry->character_id === $character->id, 404);

        $name = $entry->item?->displayName();
        $result = $taker->take($character, $entry);

        if (! $result->succeeded()) {
            return back()->with('error', $result->refusal->message());
        }

        if ($result->overdosed()) {
            $overdose = $result->overdose;

            // One sentence for all three costs, because they land together
            // and a player reading three flashes would learn less than one.
            return back()->with('error', Text::get(
                $overdose->lethal ? 'drugs.overdosed_flatline' : 'drugs.overdosed',
                [
                    'points' => $this->points($overdose->injury),
                    'hp' => $overdose->hitpoints,
                ],
            ));
        }

        return back()->with('status', $result->potency < 1.0
            ? Text::get('drugs.taken_weakened', [
                'name' => $name,
                'percent' => (int) round($result->potency * 100),
            ])
            : Text::get('drugs.taken', ['name' => $name]));
    }

    /** Half a point reads as 0.5 and two points as 2, never as 2.0. */
    private function points(float $amount): string
    {
        return rtrim(rtrim(number_format($amount, 1), '0'), '.');
    }

    /**
     * Put a row on a container, or take it back off.
     *
     * The container arrives as an id rather than a route binding so that
     * "nowhere" — the loose pile — is expressible as its absence. ItemPlacer
     * re-checks ownership of both rows; this only has to find them.
     */
    public function place(Request $request, InventoryItem $entry, ItemPlacer $placer): RedirectResponse
    {
        $character = $this->character($request);

        abort_unless($entry->character_id === $character->id, 404);

        $validated = $request->validate([
            'parent' => ['nullable', 'integer'],
        ]);

        $into = isset($validated['parent'])
            ? InventoryItem::find($validated['parent'])
            : null;

        $itemName = $entry->item?->displayName();

        $result = $placer->place($character, $entry, $into);

        if (! $result->succeeded()) {
            return back()->with('error', $result->refusal->message());
        }

        return back()->with('status', $into === null
            ? Text::get('inventory.ejected', ['item' => $itemName])
            : Text::get('inventory.stored', [
                'item' => $itemName,
                'container' => $into->item->displayName(),
            ]));
    }

    /**
     * Destroy a row, and whatever the schema takes with it.
     *
     * No dedicated writer class, unlike injecting or placing: there is exactly
     * one rule — it has to be yours — and the controller already checks that
     * to decide between a 404 and an action. A class here would be ceremony
     * around a delete.
     *
     * What it reaches is not a guard, it is a description: the confirmation
     * spends the count before the button is offered, the way DeletionImpact
     * does in the admin editor.
     */
    public function destroy(Request $request, InventoryItem $entry, ProgramRunner $runner): RedirectResponse
    {
        $character = $this->character($request);

        abort_unless($entry->character_id === $character->id, 404);

        /*
         * The deck lock, third door. Destroying the deck you are standing
         * inside — or a module fitted into it — would leave a character in
         * cyberspace on a machine that no longer exists.
         *
         * Refused here rather than in a writer class because destroying has no
         * writer class: there is one rule and this controller already checks
         * it, the same reasoning the ownership check above follows.
         */
        if ($character->isLockedInCyberspace($entry)) {
            return back()->with('error', FittingRefusal::JackedIn->message());
        }

        $name = $entry->item?->displayName() ?? '';

        /*
         * Anything running on it stops first, and the programs go back to the
         * stash. Not politeness — `inventory_items.running_on_deck_id` is
         * RESTRICT, so a deck with a live process cannot be deleted at all and
         * the attempt would be a 500 with a constraint name in it.
         *
         * RESTRICT rather than a cascade because losing the machine must not
         * delete the software, and rather than SET NULL because MySQL will not
         * allow the "both or neither" CHECK beside a referential action. The
         * same shape a skill and its book already use: the database refuses,
         * and the controller clears the dependant inside the transaction.
         */
        DB::transaction(function () use ($entry, $character, $runner): void {
            $runner->stopAll($character, $entry);

            // Contents cascade with it; fittings cascade too, which frees the
            // modules of a deck rather than destroying them.
            $entry->delete();
        });

        return redirect()
            ->route('inventory.index')
            ->with('status', Text::get('inventory.destroyed', ['item' => $name]));
    }

    /**
     * Fit this module into the equipped deck, wherever it goes.
     *
     * The socket is the fitter's to choose — see ModuleFitter::fitToDeck,
     * which is also where a skill requirement would be checked if any module
     * carried one.
     */
    public function fit(Request $request, InventoryItem $entry, ModuleFitter $fitter): RedirectResponse
    {
        $character = $this->character($request);

        abort_unless($entry->character_id === $character->id, 404);

        $result = $fitter->fitToDeck($character, $entry);

        if (! $result->succeeded()) {
            return back()->with('error', $result->refusal->message());
        }

        return back()->with('status', Text::get('decks.fitting.fitted', [
            'module' => $entry->item->displayName(),
            'socket' => $result->fitting->slot->type()->label(),
        ]));
    }

    /**
     * Start or stop a program, which is one route because it is one question.
     *
     * The same shape `deck.fit` has: the absence of the thing being asked for
     * is the request to undo it. A row that is running is asked to stop; a row
     * that is not is asked to start.
     *
     * Run-only software is never started from here. An icebreaker is started
     * BY a run — see ProgramRunner::start()'s $duringRun — so pressing this on
     * one is a refusal with a sentence attached rather than an absent button,
     * because "this only does anything during an intrusion" teaches more than
     * nothing does.
     */
    public function run(Request $request, InventoryItem $entry, ProgramRunner $runner): RedirectResponse
    {
        $character = $this->character($request);

        abort_unless($entry->character_id === $character->id, 404);

        $name = $entry->item?->displayName() ?? '';

        $result = $entry->isRunning()
            ? $runner->stop($character, $entry)
            : $runner->start($character, $entry);

        if (! $result->succeeded()) {
            return back()->with('error', $result->refusal->message());
        }

        $status = back()->with('status', Text::get(
            $result->process !== null ? 'programs.started' : 'programs.stopped',
            ['program' => $name],
        ));

        /*
         * A program's modifiers can reach the deck it runs on, so starting or
         * stopping one can be what takes a jacked-in player's machine below
         * what it needs. Asked here rather than left to the middleware so the
         * player is told in the same breath as the thing they asked for — two
         * flashes, because both are true and neither replaces the other.
         */
        $ejection = ReadinessWatch::fromConfig()->check($character);

        return $ejection === null ? $status : $status->with('error', $ejection->message());
    }

    private function character(Request $request): Character
    {
        // RequireCharacter guarantees one on every route in this group, so a
        // missing character here is a routing mistake, not a player state.
        return $request->user()->character()->firstOrFail();
    }
}
