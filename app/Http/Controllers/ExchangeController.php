<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\City\CityRefusal;
use App\Missions\MissionBoard;
use App\Missions\MissionLedger;
use App\Models\Character;
use App\Models\EntityOffer;
use App\Models\Mission;
use App\Models\System;
use App\Standing\Band;
use App\Standing\DeskBuyer;
use App\Standing\LoyaltyExchange;
use App\Standing\LoyaltyRefusal;
use App\Standing\Service;
use App\Standing\StandingLedger;
use App\Support\Text;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Number;
use Illuminate\View\View;

/**
 * Missions & Loyalty: an entity's front desk, seen from in front of it.
 *
 * REACHED BY STANDING ON IT, the way a run is planned from the cell it will be
 * run against. There is no id in the route and no way to open somebody else's
 * desk from a list: being there is the whole of the permission, which is what
 * makes walking to a grade 5 exchange worth doing.
 *
 * Nothing here can be done TO the system. An Exchange is not scanned, run
 * against or burned — `SystemType` answers all three — so this screen is the
 * only thing that happens on one.
 */
class ExchangeController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        $character = $this->character($request);

        $system = $character->isJackedIn()
            ? System::query()->at($character->gridPosition())->with('owner', 'exchange')->first()
            : null;

        // Standing somewhere else is not an error worth a sentence: the map is
        // where a desk is found, so the map is where somebody is sent back to.
        if ($system?->exchange === null || ! $system->type->isExchange()) {
            return redirect()->route('cyberspace.index');
        }

        $entity = $system->owner;
        $standing = $entity !== null
            ? (new StandingLedger)->standingFor($character, $entity)
            : 0.0;

        $welcome = $system->exchange->willDealWith($standing);

        Mission::query()->where('character_id', $character->id)->accepted()->get()
            ->each(fn (Mission $m) => app(MissionLedger::class)->retarget($m));

        return view('exchange.show', [
            ...$this->shopContext($character),
            'character' => $character,
            'system' => $system,
            'desk' => $system->exchange,
            'entity' => $entity,
            'standing' => $standing,
            'band' => Band::for($standing),
            'loyalty' => $entity !== null
                ? (new StandingLedger)->loyaltyFor($character, $entity)
                : 0,
            // ONE GATE, NOT TWO. The work and the shelf read the same column:
            // an agent who will not deal with somebody will not sell to them
            // either, and a gruff desk is a row with a number in it.
            'welcome' => $system->exchange->willDealWith($standing),

            /*
             * THE BOARD IS ROLLED ON THE WAY PAST, lazily, the shape
             * `Probe::settle()` takes. It is only asked for when the desk will
             * deal with somebody — an agent who will not employ you has
             * nothing to offer, and rolling a board nobody may take would be
             * work for a screen that cannot use it.
             */
            'board' => $welcome && $entity !== null
                ? MissionBoard::fromConfig()->forCharacter($character, $system->exchange)
                : collect(),

            // Work in progress is NOT part of a board and survives every
            // refresh: a job somebody took is theirs until it ends.
            'active' => Mission::query()
                ->where('character_id', $character->id)
                ->where('exchange_id', $system->exchange->getKey())
                ->accepted()
                ->with('targets.system', 'entity')
                ->first(),

            // Counted once here rather than per card: only a supply job reads
            // it, but the card should not be running a query to draw a meter.
            'files' => app(MissionLedger::class)->suppliable($character),
            // The systems a survey's files are in hand for, so its card can tick them off before the hand-in.
            'heldAddresses' => app(MissionLedger::class)->heldAddresses($character),

            // The shelf. Asked for only when the desk will deal with somebody
            // at all — the same reason the board is, and the same gate.
            'shelf' => $welcome && $entity !== null
                ? (new LoyaltyExchange)->shelf($entity, (int) $system->exchange->grade)
                : collect(),

            // What the desk will buy from this character, with the price and what is left of its limit.
            'buying' => $entity !== null
                ? app(DeskBuyer::class)->sellable($character, $system->exchange, $standing)
                : [],
        ]);
    }

    /** Sell to the desk you are standing on. Like redeeming, the desk is where the character is, never in the request. */
    public function sell(Request $request, int $row, DeskBuyer $buyer): RedirectResponse
    {
        $character = $this->character($request);
        $quantity = (int) $request->validate(['quantity' => ['required', 'integer', 'between:1,9999']])['quantity'];

        $system = $character->isJackedIn()
            ? System::query()->at($character->gridPosition())->with('owner', 'exchange')->first()
            : null;

        if ($system?->exchange === null || ! $system->type->isExchange()) {
            return redirect()->route('cyberspace.index');
        }

        $result = $buyer->sell($character, $system->exchange, $row, $quantity);

        return $result instanceof CityRefusal
            ? back()->with('error', $result->message())
            : back()->with('status', Text::get('city.shop.sold', ['paid' => number_format($result)]));
    }

    /**
     * Spend loyalty on one row of this desk's shelf.
     *
     * REACHED BY STANDING ON THE DESK, like everything else here: the offer is
     * in the route and the desk is not, because which desk is a fact about
     * where the character is rather than something a request may assert.
     */
    public function redeem(Request $request, EntityOffer $offer, LoyaltyExchange $store): RedirectResponse
    {
        $character = $this->character($request);

        $system = $character->isJackedIn()
            ? System::query()->at($character->gridPosition())->with('owner', 'exchange')->first()
            : null;

        if ($system?->exchange === null || ! $system->type->isExchange()) {
            return redirect()->route('cyberspace.index');
        }

        $result = $store->buy($character, $system->exchange, $offer);

        if ($result instanceof LoyaltyRefusal) {
            return back()->with('error', $result->message());
        }

        // "Is yours" is a lie about a service, which lands nowhere.
        return back()->with('status', Text::get($result instanceof Service ? 'exchange.store_served' : 'exchange.store_bought', [
            'item' => $offer->displayName(),
            'price' => Number::format((float) $offer->loyalty_price),
        ]));
    }

    private function character(Request $request): Character
    {
        return $request->user()->character()->firstOrFail();
    }
}
