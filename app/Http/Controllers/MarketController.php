<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Market\Purchaser;
use App\Models\Character;
use App\Models\Item;
use App\Models\MarketStock;
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
            ->with('item.useSkill')
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

    private function character(Request $request): Character
    {
        // RequireCharacter guarantees one on every route in this group, so a
        // missing character here is a routing mistake rather than a state.
        return $request->user()->character()->firstOrFail();
    }
}
