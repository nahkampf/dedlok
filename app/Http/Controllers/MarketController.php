<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Market\OfferBook;
use App\Market\OfferRefusal;
use App\Market\Purchaser;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\MarketOffer;
use App\Models\MarketStock;
use App\Skills\SkillProgression;
use App\Support\Text;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The shelf, and the only place money leaves a character.
 *
 * WHAT IS ON IT IS WHAT IS ON IT. The screen lists `market_stock` rows with
 * something left, joined to items still on sale — it does not list the
 * catalogue with the stock counts laid over the top, because an item nobody
 * can buy today is not something a shop is holding.
 *
 * THIS IS ALSO HOW A PLAYER FINDS OUT A SKILL EXISTS. One book per skill and a
 * skill off the market is content found rather than bought, so the shelf is
 * deliberately not the whole list — see CLAUDE.md on skills.
 */
class MarketController extends Controller
{
    public function index(Request $request): View
    {
        $character = $this->character($request);

        $shelves = MarketStock::query()
            ->available()
            // A book's hover card lists what the skill requires, and every card
            // asks its program what kind of thing it is and where its picture
            // lives (kindName(), imageType()) — so both come along, one query
            // for the shelf rather than one per card.
            ->with(['item.useSkill.prerequisites.requiredSkill', 'item.program'])
            ->get()
            ->filter(fn (MarketStock $shelf): bool => $shelf->item !== null && $shelf->item->on_market)
            ->sortBy(fn (MarketStock $shelf): string => (string) $shelf->item?->displayName())
            ->values();

        return view('market.index', [
            'character' => $character,
            // One accordion per kind of thing — RAM, Icebreaker, Imprint —
            // named by Item::kindName(), which is what the card already calls
            // it. Alphabetical, as the shelves inside each already are.
            'groups' => $shelves
                ->groupBy(fn (MarketStock $shelf): string => $shelf->item->kindName())
                ->sortKeys(),
            // Worked out here rather than in the template: what a player can
            // afford is the one number every row on this screen is read
            // against, and Blade should not be doing division.
            'creds' => (int) $character->creds,
            // What the corner tick says. EVERY row the character owns, not
            // only the loose ones: a module fitted into a deck, a program
            // running, an implant in a body are all still yours, and
            // `scopeLoose()` is the stash's question rather than this one.
            // Keyed for isset() so a shelf of forty is forty lookups.
            'owned' => $character->inventory()->distinct()->pluck('item_id')->flip()->all(),
            // skill id => level and points, for the tick and for a book's
            // <x-skill-detail>: the same map the skills screen reads, so a
            // skill is described identically wherever it is inspected.
            'injected' => $character->characterSkills()
                ->get(['skill_id', 'attained_level', 'skill_points'])
                ->mapWithKeys(fn ($row): array => [
                    (int) $row->skill_id => ['level' => (int) $row->attained_level, 'points' => (int) $row->skill_points],
                ])
                ->all(),
            'maxLevel' => SkillProgression::fromConfig()->maxLevel(),
            // The other market. Not grouped and not searched: it is whatever
            // anybody happens to be holding today, and it is short.
            'offers' => MarketOffer::query()
                ->onOffer()
                ->with(['row.item.useSkill.prerequisites.requiredSkill', 'row.item.program', 'seller'])
                ->get(),
        ]);
    }

    public function buy(Request $request, Item $item, Purchaser $purchaser): RedirectResponse
    {
        $character = $this->character($request);

        $data = $request->validate([
            'quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
        ]);

        $result = $purchaser->buy($character, $item, (int) ($data['quantity'] ?? 1));

        if (! $result->succeeded()) {
            return back()->with('error', $result->refusal->message());
        }

        return back()->with('status', Text::get('market.bought', [
            'quantity' => $result->quantity,
            'item' => $item->displayName(),
            'paid' => number_format($result->paid),
        ]));
    }

    /**
     * Put one of your own rows up for sale.
     *
     * Reached from the item's CARD rather than from this screen, because the
     * thing being sold is a particular row and the card is the one place a
     * player is looking at one.
     */
    public function list(Request $request, InventoryItem $entry, OfferBook $offers): RedirectResponse
    {
        $character = $this->character($request);

        abort_unless($entry->character_id === $character->id, 404);

        $data = $request->validate([
            'price' => ['required', 'integer', 'min:0', 'max:99999999'],
        ]);

        $result = $offers->list($character, $entry, (int) $data['price']);

        if ($result instanceof OfferRefusal) {
            return back()->with('error', $result->message());
        }

        return back()->with('status', Text::get('market.offers.listed', [
            'item' => $entry->item?->displayName(),
            'price' => number_format($result->unit_price),
        ]));
    }

    /** Take it back. Nothing was ever anywhere else, so nothing confirms it. */
    public function withdraw(Request $request, MarketOffer $offer, OfferBook $offers): RedirectResponse
    {
        $character = $this->character($request);

        abort_unless($offer->seller_character_id === $character->id, 404);

        $result = $offers->withdraw($character, $offer);

        if ($result instanceof OfferRefusal) {
            return back()->with('error', $result->message());
        }

        return back()->with('status', Text::get('market.offers.withdrawn', [
            'item' => $result->item?->displayName(),
        ]));
    }

    /**
     * Buy what somebody else is holding.
     *
     * NOT `buy()`, which is the shelf. The two are different enough to want
     * different names: one conjures a copy of a catalogue entry, this one
     * hands over an object with a history written on it.
     */
    public function take(Request $request, MarketOffer $offer, OfferBook $offers): RedirectResponse
    {
        $character = $this->character($request);

        $data = $request->validate([
            'quantity' => ['nullable', 'integer', 'min:1', 'max:9999'],
        ]);

        $quantity = (int) ($data['quantity'] ?? 1);

        // Read before the sale, because a sale that empties the pile deletes
        // both the offer and the row, and a partial one may have merged what
        // was bought into a pile of the buyer's own.
        $name = $offer->row?->item?->displayName();
        $price = $offer->total($quantity);

        $result = $offers->buy($character, $offer, $quantity);

        if ($result instanceof OfferRefusal) {
            return back()->with('error', $result->message());
        }

        return back()->with('status', Text::get('market.offers.sold', [
            'quantity' => $quantity,
            'item' => $name,
            'price' => number_format($price),
        ]));
    }

    private function character(Request $request): Character
    {
        // RequireCharacter guarantees one on every route in this group, so a
        // missing character here is a routing mistake rather than a state.
        return $request->user()->character()->firstOrFail();
    }
}
