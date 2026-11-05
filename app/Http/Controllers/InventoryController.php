<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Combat\ArmourWearer;
use App\Combat\LoadRefusal;
use App\Combat\WeaponLoader;
use App\Combat\WeaponWielder;
use App\Combat\WearRefusal;
use App\Combat\WieldRefusal;
use App\Cyberspace\ExtractedFile;
use App\Cyberspace\ReadinessWatch;
use App\Cyberspace\ScanImporter;
use App\Cyberspace\ScanImportRefusal;
use App\Decks\FittingRefusal;
use App\Decks\ModuleFitter;
use App\Drugs\DrugTaker;
use App\Equipment\BeltEquipper;
use App\Equipment\BeltRefusal;
use App\Inventory\ItemPlacer;
use App\Inventory\SkillInjector;
use App\Items\Consumer;
use App\Items\UseEffect;
use App\Items\UseRefusal;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\System;
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
     * Read a scan file into your own records.
     *
     * The HTTP-shaped half of `App\Cyberspace\ScanImporter`. Nothing is
     * confirmed: the file is spent, but what it is spent ON is the only thing
     * it was ever for, and a dialog in front of it would be asking somebody to
     * confirm using a thing by using it.
     */
    /**
     * The words of a text file, drawn by `Ansi` as a fragment for the reader dialog.
     *
     * Yours, and only once it is a text file: a sealed dump carries its words too, and reading them before it is broken
     * open would make the seal decoration.
     */
    public function text(Request $request, InventoryItem $entry): View
    {
        abort_unless($entry->character_id === $this->character($request)->id, 404);
        abort_unless($entry->item?->slug === ExtractedFile::TEXT_SLUG, 404);

        $text = $entry->fileText;
        abort_if($text === null, 404);

        return view('inventory._text', ['text' => $text]);
    }

    public function read(Request $request, InventoryItem $entry, ScanImporter $importer): RedirectResponse
    {
        $character = $this->character($request);

        abort_unless($entry->character_id === $character->id, 404);

        $result = $importer->import($character, $entry);

        if ($result instanceof ScanImportRefusal) {
            return back()->with('error', $result->message());
        }

        return back()->with('status', Text::get('cyberspace.reading.imported', [
            'system' => System::query()->where('address', $result->address)->first()?->displayName()
                ?? $result->address,
        ]));
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
    /**
     * Put armour on, or take it off. One route for both directions, like `run` and `place`: a
     * piece that is worn is asked to come off, one that is not is asked to go on.
     */
    public function wear(Request $request, InventoryItem $entry, ArmourWearer $wearer): RedirectResponse
    {
        $character = $this->character($request);

        abort_unless($entry->character_id === $character->id, 404);

        $name = $entry->item?->displayName() ?? '';
        $worn = $entry->isWorn();
        $result = $worn ? $wearer->remove($character, $entry) : $wearer->wear($character, $entry);

        return $result instanceof WearRefusal
            ? back()->with('error', $result->message())
            : back()->with('status', Text::get($worn ? 'combat.armour.took_off' : 'combat.armour.put_on', ['item' => $name]));
    }

    /** Use one copy of a consumable: spent, and what it does lands at once. */
    public function consume(Request $request, InventoryItem $entry, Consumer $consumer): RedirectResponse
    {
        $character = $this->character($request);

        abort_unless($entry->character_id === $character->id, 404);

        $name = $entry->item?->displayName() ?? '';
        $result = $consumer->use($character, $entry);

        if ($result instanceof UseRefusal) {
            return back()->with('error', $result->message());
        }

        $done = [];

        foreach ($result as $kind => $amount) {
            $done[] = UseEffect::from($kind)->done($amount);
        }

        return back()->with('status', Text::get('consumables.used', ['item' => $name, 'effects' => implode(' ', $done)]));
    }

    /** Put a drug on the belt, or take it off. One route for both directions, like `wear`. */
    public function belt(Request $request, InventoryItem $entry, BeltEquipper $belt): RedirectResponse
    {
        $character = $this->character($request);

        abort_unless($entry->character_id === $character->id, 404);

        $name = $entry->item?->displayName() ?? '';
        $on = $entry->isBelted();
        $result = $on ? $belt->take($character, $entry) : $belt->put($character, $entry);

        return $result instanceof BeltRefusal
            ? back()->with('error', $result->message())
            : back()->with('status', Text::get($on ? 'equipment.belt.took_off' : 'equipment.belt.put_on_done', ['item' => $name]));
    }

    /** Pick a weapon up, or put it down. One route for both directions, like `wear`. */
    public function wield(Request $request, InventoryItem $entry, WeaponWielder $wielder): RedirectResponse
    {
        $character = $this->character($request);

        abort_unless($entry->character_id === $character->id, 404);

        $name = $entry->item?->displayName() ?? '';
        $held = $entry->isWielded();
        $result = $held ? $wielder->unwield($character, $entry) : $wielder->wield($character, $entry);

        if ($result === WieldRefusal::RequiresSkill) {
            return back()->with('error', $result->message([
                'skill' => $wielder->gate($entry->item->weapon->class)?->title ?? '',
                'level' => (int) config('combat.gate_level'),
            ]));
        }

        return $result instanceof WieldRefusal
            ? back()->with('error', $result->message())
            : back()->with('status', Text::get($held ? 'combat.weapons.put_away' : 'combat.weapons.took_up', ['item' => $name]));
    }

    /** Fill a weapon's magazine from the stash with the round the player picked. */
    public function load(Request $request, InventoryItem $entry, WeaponLoader $loader): RedirectResponse
    {
        $character = $this->character($request);

        abort_unless($entry->character_id === $character->id, 404);

        $ammo = Item::query()->with('ammunition')->find((int) $request->input('ammo'));

        if ($ammo === null) {
            return back()->with('error', LoadRefusal::NotAmmunition->message());
        }

        $result = $loader->load($character, $entry, $ammo);

        return $result instanceof LoadRefusal
            ? back()->with('error', $result->message())
            : back()->with('status', Text::get('combat.loading.loaded_ok', ['item' => $ammo->displayName(), 'weapon' => $entry->item?->displayName() ?? '']));
    }

    /** Take every round out of a weapon and put it back in the stash. */
    public function unload(Request $request, InventoryItem $entry, WeaponLoader $loader): RedirectResponse
    {
        $character = $this->character($request);

        abort_unless($entry->character_id === $character->id, 404);

        $result = $loader->unload($character, $entry);

        return $result instanceof LoadRefusal
            ? back()->with('error', $result->message())
            : back()->with('status', Text::get('combat.loading.unloaded_ok', ['weapon' => $entry->item?->displayName() ?? '']));
    }

    public function take(Request $request, InventoryItem $entry, DrugTaker $taker): RedirectResponse
    {
        $character = $this->character($request);

        abort_unless($entry->character_id === $character->id, 404);

        $name = $entry->item?->displayName();
        $result = $taker->take($character, $entry);

        if (! $result->succeeded()) {
            return back()->with('error', $result->refusal->message());
        }

        // What a consumable half did at once ("You recover 12 hit points."), added to whichever sentence follows.
        $instant = collect($result->instant)
            ->map(fn (int $amount, string $kind): string => UseEffect::from($kind)->done($amount))
            ->implode(' ');

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
            ).($instant === '' ? '' : ' '.$instant));
        }

        return back()->with('status', ($result->potency < 1.0
            ? Text::get('drugs.taken_weakened', [
                'name' => $name,
                'percent' => (int) round($result->potency * 100),
            ])
            : Text::get('drugs.taken', ['name' => $name])).($instant === '' ? '' : ' '.$instant));
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

        // A pile loses only what was asked for (one, by default); the row goes with its last copy.
        $count = $entry->quantity > 1
            ? (int) ($request->validate(['quantity' => ['nullable', 'integer', 'min:1']])['quantity'] ?? 1)
            : 1;

        if ($count < $entry->quantity) {
            $entry->decrement('quantity', $count);

            return redirect()
                ->route('inventory.index')
                ->with('status', Text::get('inventory.destroyed', ['item' => $name]));
        }

        /*
         * Anything running on it stops first, and the programs go back to the
         * stash. Not politeness — `inventory_items.running_on_deck_id` is
         * RESTRICT, so a deck with a live process cannot be deleted at all and
         * the attempt would be a 500 with a constraint name in it.
         *
         * RESTRICT rather than a cascade because losing the machine must not
         * delete the software, and rather than SET NULL because MySQL will not
         * allow the "both or neither" CHECK beside a referential action. The
         * same shape a skill and its imprint already use: the database refuses,
         * and the controller clears the dependant inside the transaction.
         */
        DB::transaction(function () use ($entry, $character, $runner): void {
            $runner->stopAll($character, $entry);

            // A loaded weapon hands its rounds back first. They are property, and the row they
            // sit on is about to go.
            if ($entry->rounds() > 0) {
                app(WeaponLoader::class)->emptyInto($character, $entry);
            }

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

        // The Running modal asks to be opened again after the page reloads.
        $reopen = $request->input('reopen') === 'processes' ? ['reopen' => 'processes'] : [];

        if (! $result->succeeded()) {
            return back()->with('error', $result->refusal->message())->with($reopen);
        }

        $status = back()->with($reopen)->with('status', Text::get(
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

    /** The Running modal's Run list, as a fragment: what the equipped deck could start right now. */
    public function startable(Request $request, ProgramRunner $runner): View
    {
        $character = $this->character($request);
        $deck = $character->equippedDeck;

        return view('programs._startable', [
            'programs' => $runner->startable($character),
            'hasDeck' => $deck !== null,
        ]);
    }

    private function character(Request $request): Character
    {
        // RequireCharacter guarantees one on every route in this group, so a
        // missing character here is a routing mistake, not a player state.
        return $request->user()->character()->firstOrFail();
    }
}
