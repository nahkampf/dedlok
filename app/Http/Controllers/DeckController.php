<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Decks\DeckEquipper;
use App\Decks\DeckTotals;
use App\Decks\FittingRefusal;
use App\Decks\ModuleFitter;
use App\Decks\ThermalExposure;
use App\Decryption\DecryptionQueue;
use App\Models\Character;
use App\Models\DeckSlot;
use App\Models\DecryptionEntry;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Skill;
use App\Programs\ProgramRunner;
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
    /**
     * The deck this character is carrying, whichever it is.
     *
     * The rail cannot link to a row it does not know, and a nav item that
     * appears and disappears with your loadout is a nav item nobody trusts.
     * So this resolves it, and says so when there is nothing to resolve —
     * sending you to the stash, which is where a deck gets equipped.
     */
    public function index(Request $request): RedirectResponse
    {
        $deck = $this->character($request)->equippedDeck;

        if ($deck === null) {
            return redirect()
                ->route('inventory.index')
                ->with('error', FittingRefusal::NotEquipped->message());
        }

        return redirect()->route('deck.show', $deck);
    }

    public function show(Request $request, InventoryItem $entry, Resolver $resolver, ProgramRunner $runner): View
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
            // The decryption queue, with each file's odds: only the equipped deck runs a decryptor, so only it shows one.
            'decryption' => $character->hasEquipped($entry) ? $this->decryption($character) : null,
            // What that load is doing to each thing fitted in it. Kept apart
            // from the totals because a deck's tolerance is its weakest part
            // rather than the sum of its parts.
            'thermal' => ThermalExposure::of(
                $totals->netThermalLoad(),
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

            /*
             * What is running ON THIS DECK, which is not the same as what the
             * character is running: only the equipped deck's processes count
             * for modifiers, but an unequipped deck has none at all — putting
             * a machine down stops everything on it. So this is empty for any
             * deck but the one being carried, and the screen says so rather
             * than showing an empty box with no explanation.
             */
            'processes' => $entry->processes()
                ->with('item.program')
                ->orderBy('started_at')
                ->get(),

            // What compute and memory the running set is already spending,
            // against what the deck resolves. The budget a start is checked
            // against, shown where the starting happens.
            'spent' => $runner->spentOn($character, (int) $entry->id),

            /*
             * Deck storage: everything written rather than carried.
             *
             * Split into programs and data because they are two different
             * things that happen to share an inventory — one is something you
             * RUN and the other is something you HOLD — and a single list
             * mixing an icebreaker with a stolen file would be sorted by name
             * and useful to nobody.
             */
            'stored' => $character->inventoryIn(Item::CONTAINER_DATA)
                ->partition(fn (InventoryItem $row) => $row->item?->program !== null),

            // What a stored row could be put away into. The physical side,
            // because a memory shard is a physical object that accepts data —
            // the case `container` alone could not express.
            'storageContainers' => $character->inventoryIn(Item::CONTAINER_PHYSICAL)
                ->filter(fn (InventoryItem $row) => $row->item?->isContainer() ?? false)
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
     * The rule itself lives on DeckTotals now, because the jack-in gate is a
     * second caller and readiness has to agree with what the screen shows.
     * This is the name the view already knew it by.
     */
    private function totals(Character $character, InventoryItem $entry, Resolver $resolver): DeckTotals
    {
        return DeckTotals::forCharacter($character, $entry, $resolver);
    }

    private function character(Request $request): Character
    {
        // RequireCharacter guarantees one on every route in this group, so a
        // missing character here is a routing mistake, not a player state.
        return $request->user()->character()->firstOrFail();
    }

    /**
     * The queue as a screen draws it, settled first so the clock it shows is true.
     *
     * @return array{entries: list<array{entry: DecryptionEntry, row: InventoryItem, odds: array{chance: float, seconds: float, skill: Skill}|null}>, running: bool}
     */
    private function decryption(Character $character): array
    {
        $queue = DecryptionQueue::make();
        $queue->settle($character);

        $entries = [];

        foreach (DecryptionEntry::query()->with('file.item')->where('character_id', $character->id)->orderBy('position')->get() as $entry) {
            if ($entry->file !== null) {
                $entries[] = ['entry' => $entry, 'row' => $entry->file, 'odds' => $queue->odds($character, $entry->file)];
            }
        }

        return ['entries' => $entries, 'running' => DecryptionQueue::canWork($character)];
    }
}
