<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\City\CityClock;
use App\City\CityRefusal;
use App\City\Clinic;
use App\City\ClinicService;
use App\City\ShopKeeper;
use App\Models\Character;
use App\Models\Location;
use App\Support\Text;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * One place: what it is, and what can be done there.
 *
 * THE FIRST DOOR THAT ADDRESSES A LOCATION DIRECTLY, so it resolves the slug
 * through `Location::scopeVisibleTo()` and a place the character has not found
 * is the same 404 as one that does not exist. Reading a place you know in
 * another district is allowed; acting needs the body there, and the writers
 * (`ShopKeeper`, `Clinic`) ask `Presence` themselves — this page hiding a
 * button guards nothing.
 */
class PlaceController extends Controller
{
    public function show(Request $request, string $slug, CityClock $clock, Clinic $clinic, ShopKeeper $shop): View|RedirectResponse
    {
        $character = $this->character($request);

        if ($character->isJackedIn()) {
            return redirect()->route('cyberspace.index')->with('error', Text::get('city.jack_out_first'));
        }

        $clock->settle($character);
        $character->refresh();

        $place = $this->place($character, $slug);

        return view('city.place', [
            'character' => $character,
            'place' => $place->load('district'),
            'here' => $character->district_id === $place->district_id,
            // Stock brought up to date on the way past: a restock is lazy.
            'wares' => $shop->wares($place),
            'sellable' => $shop->sellable($character, $place),
            // The same call `treat()` charges from.
            'treatments' => $clinic->quote($character, $place),
        ]);
    }

    public function buy(Request $request, string $slug, int $item, ShopKeeper $shop): RedirectResponse
    {
        $character = $this->character($request);
        $place = $this->place($character, $slug);
        $quantity = (int) $request->validate(['quantity' => ['required', 'integer', 'between:1,99']])['quantity'];

        $result = $shop->buy($character, $place, $item, $quantity);

        return $result instanceof CityRefusal
            ? back()->with('error', $result->message())
            : back()->with('status', Text::get('city.shop.bought', ['quantity' => $result]));
    }

    public function sell(Request $request, string $slug, int $row, ShopKeeper $shop): RedirectResponse
    {
        $character = $this->character($request);
        $place = $this->place($character, $slug);
        $quantity = (int) $request->validate(['quantity' => ['required', 'integer', 'between:1,9999']])['quantity'];

        $result = $shop->sell($character, $place, $row, $quantity);

        return $result instanceof CityRefusal
            ? back()->with('error', $result->message())
            : back()->with('status', Text::get('city.shop.sold', ['paid' => number_format($result)]));
    }

    public function treat(Request $request, string $slug, string $service, Clinic $clinic): RedirectResponse
    {
        $character = $this->character($request);
        $place = $this->place($character, $slug);
        $kind = ClinicService::tryFrom($service);

        $result = $kind === null ? CityRefusal::NotSold : $clinic->treat($character, $place, $kind);

        return $result instanceof CityRefusal
            ? back()->with('error', $result->message())
            : back()->with('status', Text::get('city.clinic.treated', ['paid' => number_format($result)]));
    }

    private function character(Request $request): Character
    {
        return $request->user()->character()->firstOrFail();
    }

    /** A place the character cannot see is a place that is not there. */
    private function place(Character $character, string $slug): Location
    {
        return Location::query()->visibleTo($character)->where('slug', $slug)->firstOrFail();
    }
}
