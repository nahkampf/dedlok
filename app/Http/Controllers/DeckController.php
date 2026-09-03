<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Decks\DeckEquipper;
use App\Decks\DeckTotals;
use App\Decks\FittingRefusal;
use App\Decks\HeatExposure;
use App\Decks\ModuleFitter;
use App\Models\Character;
use App\Models\DeckSlot;
use App\Models\InventoryItem;
use App\Stats\Resolver;
use App\Support\Text;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Looking at a cyberdeck you own.
 *
 * A deck is an inventory row, not a screen of its own kind — which is why the
 * route takes the row and not the item. Two players holding the same model of
 * deck will have different things fitted in them the moment fitting exists,
 * and the row is the only thing that can tell them apart.
 *
 * Read-only, and it has to stay that way for now: nothing is fitted into
 * anything yet, so every socket on this screen is empty and the screen says
 * so rather than implying a loadout that does not exist.
 */
class DeckController extends Controller
{
    public function show(Request $request, InventoryItem $entry, Resolver $resolver): View
    {
        $character = $this->character($request);

        // Someone else's row is not a refusal to explain, it is a row that
        // does not exist as far as this player is concerned — the same call
        // InventoryController makes.
        abort_unless($entry->character_id === $character->id, 404);

        $item = $entry->item;

        // Neither is a rifle. There is no board to draw and nothing to say
        // about one, so this is a wrong URL rather than an empty screen.
        abort_unless($item?->isCyberdeck(), 404);

        $entry->loadMissing('item.deckSlots', 'fittings.slot', 'fittings.module.item.deckModule');

        return view('decks.show', [
            'character' => $character,
            'entry' => $entry,
            'item' => $item,
            // What is in each socket, keyed by slot id — the shape the board
            // reads. A socket with no key is empty, which is most of them.
            'fitted' => $entry->fittings->keyBy('deck_slot_id'),
            // The one place a deck is totalled, and the one place the
            // equipped/unequipped rule is applied — see below.
            'totals' => $totals = $this->totals($character, $entry, $resolver),
            'equipped' => $character->hasEquipped($entry),
            // What that heat is doing to each thing fitted in it. Kept apart
            // from the totals because a deck's tolerance is its weakest part
            // rather than the sum of its parts.
            'heat' => HeatExposure::of(
                $totals->netHeat(),
                $entry->fittings,
                $character->modifiers(),
                $resolver,
            ),
            // What the player could put in, ordered so the obvious choice is
            // first. Loose only: a module in a bag or in another deck is not
            // to hand, and loose() is the one place that decides what is.
            'spares' => $character->inventory()
                ->with('item.deckModule')
                ->loose()
                ->get()
                ->filter(fn (InventoryItem $row) => $row->item?->isModule() ?? false)
                ->sortBy(fn (InventoryItem $row) => $row->item->displayName())
                ->values(),
        ]);
    }

    /**
     * Put a module into a socket, or take out whatever is in it.
     *
     * One action for both, like InventoryController::place: an absent module
     * is a request to empty the socket. App\Decks\ModuleFitter re-checks
     * ownership of everything; this only has to find the rows.
     */
    public function fit(Request $request, InventoryItem $entry, DeckSlot $slot, ModuleFitter $fitter): RedirectResponse
    {
        $character = $this->character($request);

        abort_unless($entry->character_id === $character->id, 404);

        $validated = $request->validate([
            'module' => ['nullable', 'integer'],
        ]);

        if (! isset($validated['module'])) {
            $result = $fitter->unfit($character, $entry, $slot);

            return $result->succeeded()
                ? back()->with('status', Text::get('decks.fitting.removed'))
                : back()->with('error', $result->refusal->message());
        }

        $module = InventoryItem::with('item')->find($validated['module']);

        if ($module === null) {
            return back()->with('error', FittingRefusal::NotOwned->message());
        }

        $result = $fitter->fit($character, $entry, $slot, $module);

        if (! $result->succeeded()) {
            return back()->with('error', $result->refusal->message());
        }

        return back()->with('status', Text::get('decks.fitting.fitted', [
            'module' => $module->item->displayName(),
            'socket' => $slot->type()->label(),
        ]));
    }

    /** Empty every socket. Nothing is destroyed, so nothing confirms it. */
    public function strip(Request $request, InventoryItem $entry, ModuleFitter $fitter): RedirectResponse
    {
        $character = $this->character($request);

        abort_unless($entry->character_id === $character->id, 404);

        $result = $fitter->strip($character, $entry);

        if (! $result->succeeded()) {
            return back()->with('error', $result->refusal->message());
        }

        return back()->with('status', Text::plural('decks.fitting.stripped', $result->removed));
    }

    public function equip(Request $request, InventoryItem $entry, DeckEquipper $equipper): RedirectResponse
    {
        $character = $this->character($request);

        abort_unless($entry->character_id === $character->id, 404);

        $result = $equipper->equip($character, $entry);

        if (! $result->succeeded()) {
            return back()->with('error', $result->refusal->message());
        }

        return back()->with('status', Text::get('decks.equip.equipped', [
            'deck' => $entry->item->displayName(),
        ]));
    }

    /**
     * Put the deck back in the stash, with or without its modules.
     *
     * No {entry}: one column holds one value, so there is only ever one deck
     * this could mean and naming it would be a second chance to be wrong.
     */
    public function unequip(Request $request, DeckEquipper $equipper, ModuleFitter $fitter): RedirectResponse
    {
        $character = $this->character($request);

        $validated = $request->validate([
            'strip' => ['nullable', 'boolean'],
        ]);

        $result = $equipper->unequip($character, (bool) ($validated['strip'] ?? false), $fitter);

        if (! $result->succeeded()) {
            return back()->with('error', $result->refusal->message());
        }

        return back()->with('status', $result->removed > 0
            ? Text::plural('decks.equip.unequipped_stripped', $result->removed)
            : Text::get('decks.equip.unequipped'));
    }

    /**
     * What this deck adds up to, resolved if it is the one being carried.
     *
     * MECHANICS: the EQUIPPED deck resolves, because modifiers belong to a
     * character and only one deck is theirs at a time; every other deck shows
     * its bases, since nothing about a deck in the stash depends on who owns
     * it. The sum is the resolver's input either way — there is no second
     * implementation of it for the unequipped case.
     *
     * Readiness therefore reads the resolved numbers too, which is what makes
     * a skill that cuts draw able to bring an over-committed deck into spec.
     * MECHANICS names that as how over-commitment is meant to be solved.
     */
    private function totals(Character $character, InventoryItem $entry, Resolver $resolver): DeckTotals
    {
        $totals = DeckTotals::of($entry->fittings);

        return $character->hasEquipped($entry)
            ? $totals->resolvedWith($character->modifiers(), $resolver)
            : $totals;
    }

    private function character(Request $request): Character
    {
        // RequireCharacter guarantees one on every route in this group, so a
        // missing character here is a routing mistake, not a player state.
        return $request->user()->character()->firstOrFail();
    }
}
