<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\City\CityClock;
use App\City\CityRefusal;
use App\City\Clinic;
use App\City\ClinicService;
use App\City\Encounters;
use App\City\EncounterStart;
use App\City\Kitchen;
use App\City\ShopKeeper;
use App\City\Surgeon;
use App\City\SurgeryOperation;
use App\Insurance\Insurance;
use App\Insurance\InsuranceKind;
use App\Models\Character;
use App\Models\InsuranceOffer;
use App\Models\InsurancePolicy;
use App\Models\InventoryItem;
use App\Models\Location;
use App\Support\Text;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
    public function show(Request $request, string $slug, CityClock $clock, Clinic $clinic, ShopKeeper $shop, Surgeon $surgeon, Encounters $encounters, Insurance $insurance): View|RedirectResponse
    {
        $character = $this->character($request);

        if ($character->isJackedIn()) {
            return redirect()->route('cyberspace.index')->with('error', Text::get('city.jack_out_first'));
        }

        $clock->settle($character);
        $character->refresh();

        // A pass that matured on the way in may have opened one: it owns the screen now.
        if ($character->current_encounter_id !== null) {
            return redirect()->route('encounter.show');
        }

        $place = $this->place($character, $slug);

        // Arriving is coming to a place that is not the one last looked at, so a reload is not an arrival. A chance
        // encounter there takes the screen.
        if (session('last_place') !== $place->getKey()) {
            session(['last_place' => $place->getKey()]);

            if ($encounters->maybeOpenOnArrival($character, $place) !== null) {
                return redirect()->route('encounter.show');
            }
        }

        $wares = $shop->wares($place);

        return view('city.place', [
            ...$this->shopContext($character),
            'character' => $character,
            'place' => $place->load('district'),
            'here' => $character->district_id === $place->district_id,
            // Stock brought up to date on the way past: a restock is lazy.
            'wares' => $wares,
            'sellable' => $shop->sellable($character, $place),
            'surgeries' => $this->surgeries($character, $place, $surgeon),
            // What buying-and-fitting each implant on the shelf would cost, or why not.
            'fits' => $wares
                ->filter(fn ($w): bool => $w->item?->implantRegion() !== null)
                ->mapWithKeys(fn ($w): array => [$w->item_id => $surgeon->quoteFit($character, $place, $w->item)])
                ->all(),
            // The same call `treat()` charges from.
            'treatments' => $clinic->quote($character, $place),
            'policies' => $this->policies($character, $place, $insurance),
            'menu' => $place->meals()->orderBy('price')->get(),
            // What is going on here, which a button starts. Only while the body is here.
            'happenings' => $character->district_id === $place->district_id
                ? $encounters->eligible($character, EncounterStart::Place, null, $place->getKey())
                : collect(),
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

    public function buyAndFit(Request $request, string $slug, int $item, Surgeon $surgeon): RedirectResponse
    {
        $character = $this->character($request);
        $place = $this->place($character, $slug);

        $result = $surgeon->buyAndFit($character, $place, $item);

        return $result instanceof CityRefusal
            ? back()->with('error', $result->message())
            : back()->with('status', Text::get('city.surgery.begun'));
    }

    public function operate(Request $request, string $slug, int $row, string $operation, Surgeon $surgeon): RedirectResponse
    {
        $character = $this->character($request);
        $place = $this->place($character, $slug);
        $op = SurgeryOperation::tryFrom($operation);
        $implant = InventoryItem::query()->with('item')->where('character_id', $character->getKey())->find($row);

        $result = $op === null || $implant === null ? CityRefusal::CannotOperate : $surgeon->begin($character, $place, $implant, $op);

        return $result instanceof CityRefusal
            ? back()->with('error', $result->message())
            : back()->with('status', Text::get('city.surgery.begun'));
    }

    /**
     * Every implant of theirs this clinic would touch, with the same quote `begin()` charges from.
     *
     * @return list<array{row: InventoryItem, op: SurgeryOperation, quote: array{fee: int, seconds: int, chance: float|null, tier: int}}>
     */
    private function surgeries(Character $character, Location $place, Surgeon $surgeon): array
    {
        if (! $place->surgery()->exists()) {
            return [];
        }

        $out = [];

        foreach (InventoryItem::query()->with('item')->where('character_id', $character->getKey())->whereHas('item', static fn ($q) => $q->whereNotNull('implant_region'))->orderBy('id')->get() as $row) {
            $op = $row->installed_at === null ? SurgeryOperation::Install : SurgeryOperation::Remove;
            $quote = $surgeon->quote($character, $place, $row, $op);

            if (is_array($quote)) {
                $out[] = ['row' => $row, 'op' => $op, 'quote' => $quote];
            }
        }

        return $out;
    }

    /**
     * What this place sells in cover, by kind, with what the character holds of each.
     *
     * @return list<array{kind: InsuranceKind, offers: Collection<int, InsuranceOffer>, active: ?InsurancePolicy, queued: ?InsurancePolicy}>
     */
    private function policies(Character $character, Location $place, Insurance $insurance): array
    {
        $offers = $place->insuranceOffers()->orderBy('share')->get()->groupBy(fn ($o): string => $o->kind->value);
        $out = [];

        foreach (InsuranceKind::cases() as $kind) {
            if (isset($offers[$kind->value])) {
                $out[] = ['kind' => $kind, 'offers' => $offers[$kind->value], 'active' => $insurance->active($character, $kind), 'queued' => $insurance->queued($character, $kind)];
            }
        }

        return $out;
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

    public function eat(Request $request, string $slug, int $meal, Kitchen $kitchen): RedirectResponse
    {
        $character = $this->character($request);
        $result = $kitchen->eat($character, $this->place($character, $slug), $meal);

        return $result instanceof CityRefusal
            ? back()->with('error', $result->message())
            : back()->with('status', Text::get('city.food.eaten'));
    }

    public function treat(Request $request, string $slug, string $service, Clinic $clinic): RedirectResponse
    {
        $character = $this->character($request);
        $place = $this->place($character, $slug);
        $kind = ClinicService::tryFrom($service);

        $partial = $request->filled('points') ? (int) $request->validate(['points' => ['integer', 'min:1']])['points'] : null;

        $result = $kind === null ? CityRefusal::NotSold : $clinic->treat($character, $place, $kind, $partial);

        return $result instanceof CityRefusal
            ? back()->with('error', $result->message())
            : back()->with('status', Text::get('city.clinic.treated', ['paid' => number_format($result)]));
    }

    public function insure(Request $request, string $slug, string $kind, int $share, Insurance $insurance): RedirectResponse
    {
        $character = $this->character($request);
        $place = $this->place($character, $slug);
        $type = InsuranceKind::tryFrom($kind);

        $result = $type === null ? CityRefusal::NotSold : $insurance->sign($character, $place, $type, $share);

        return $result instanceof CityRefusal
            ? back()->with('error', $result->message())
            : back()->with('status', Text::get('city.insurance.signed', ['paid' => number_format($result)]));
    }

    public function encounter(Request $request, string $slug, int $encounter, Encounters $encounters): RedirectResponse
    {
        $character = $this->character($request);
        $place = $this->place($character, $slug);

        $result = $encounters->openAtPlace($character, $place, $encounter);

        return $result instanceof CityRefusal
            ? back()->with('error', $result->message())
            : redirect()->route('encounter.show');
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
