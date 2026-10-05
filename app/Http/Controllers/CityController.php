<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\City\CityClock;
use App\City\CityRefusal;
use App\City\Explorer;
use App\City\Traveller;
use App\City\TravelMode;
use App\Models\Character;
use App\Models\CityActivity;
use App\Models\District;
use App\Models\Location;
use App\Support\Text;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The City: a map of districts, and behind each one a map of its places, and
 * the two things a body can do in it — travel, and explore.
 *
 * They refuse a jacked-in character: the City is the physical half, so you must
 * jack out to be in it. Any district's map may be opened from anywhere; what a
 * character can SEE in it is `Location::scopeVisibleTo()` and nothing else, and
 * no count of what is hidden is ever drawn. Every request settles what has
 * matured on the way in, which is what makes a pass finish when somebody is
 * looking; `city:settle` covers the rest.
 */
class CityController extends Controller
{
    public function index(Request $request, CityClock $clock, Traveller $traveller): View|RedirectResponse
    {
        $character = $this->character($request);

        if ($character->isJackedIn()) {
            return $this->jackOutFirst();
        }

        $this->settle($clock, $character);

        if ($character->current_encounter_id !== null) {
            return redirect()->route('encounter.show');
        }

        return view('city.index', [
            'character' => $character,
            'districts' => District::query()->orderBy('name')->get(),
            'links' => District::links(),
            ...$this->activity($character),
        ]);
    }

    public function district(Request $request, District $district, CityClock $clock, Traveller $traveller, Explorer $explorer): View|RedirectResponse
    {
        $character = $this->character($request);

        if ($character->isJackedIn()) {
            return $this->jackOutFirst();
        }

        $this->settle($clock, $character);

        if ($character->current_encounter_id !== null) {
            return redirect()->route('encounter.show');
        }

        $state = $this->activity($character);

        $here = $character->district_id === $district->getKey();

        return view('city.district', [
            'character' => $character,
            'district' => $district,
            'here' => $here,
            'locations' => Location::query()
                ->where('district_id', $district->getKey())
                ->visibleTo($character)
                ->withExists(['shopItems', 'clinicServices', 'surgery'])
                ->orderBy('name')
                ->get(),
            // What each way of getting there would cost, from the same call
            // `begin()` makes. Only while nothing is under way.
            'quote' => $state['activity'] === null && ! $here ? $traveller->quote($character, $district) : null,
            'exploreSeconds' => $state['activity'] === null && $here ? $explorer->duration($character) : null,
            ...$state,
        ]);
    }

    public function travel(Request $request, District $district, Traveller $traveller, CityClock $clock): RedirectResponse
    {
        $character = $this->character($request);
        $mode = TravelMode::tryFrom((string) $request->input('mode')) ?? TravelMode::Walk;

        $this->settle($clock, $character);

        if ($character->current_encounter_id !== null) {
            return redirect()->route('encounter.show');
        }

        $result = $traveller->begin($character, $district, $mode);

        return $this->answer($result, Text::get('city.travel_begun', ['district' => $district->name]));
    }

    public function explore(Request $request, Explorer $explorer, CityClock $clock): RedirectResponse
    {
        $character = $this->character($request);

        $this->settle($clock, $character);

        if ($character->current_encounter_id !== null) {
            return redirect()->route('encounter.show');
        }

        return $this->answer($explorer->begin($character), Text::get('city.explore_begun'));
    }

    public function cancel(Request $request, Traveller $traveller, Explorer $explorer, CityClock $clock): RedirectResponse
    {
        $character = $this->character($request);

        $this->settle($clock, $character);

        if ($character->current_encounter_id !== null) {
            return redirect()->route('encounter.show');
        }

        $activity = CityActivity::query()->whereKey($character->getKey())->first();

        $result = $activity?->kind->value === 'explore'
            ? $explorer->cancel($character)
            : $traveller->cancel($character);

        return $this->answer($result, Text::get('city.cancelled'));
    }

    private function character(Request $request): Character
    {
        return $request->user()->character()->firstOrFail();
    }

    private function jackOutFirst(): RedirectResponse
    {
        return redirect()->route('cyberspace.index')->with('error', Text::get('city.jack_out_first'));
    }

    /** @param  CityActivity|CityRefusal|true  $result */
    private function answer(mixed $result, string $ok): RedirectResponse
    {
        return $result instanceof CityRefusal
            ? back()->with('error', $result->message())
            : back()->with('status', $ok);
    }

    /** Settle what has matured and say so on this page. */
    private function settle(CityClock $clock, Character $character): void
    {
        $said = array_map(
            static fn (array $m): string => Text::get("notifications.messages.{$m[0]}", $m[1]),
            $clock->settle($character),
        );

        if ($said !== []) {
            session()->now('status', implode(' ', $said));
            $character->refresh();
        }
    }

    /**
     * What is under way, for the screen.
     *
     * @return array{activity: ?CityActivity, remaining: int, route: list<string>, inPass: ?string}
     */
    private function activity(Character $character): array
    {
        $activity = CityActivity::query()->whereKey($character->getKey())->first();

        if ($activity === null) {
            return ['activity' => null, 'remaining' => 0, 'route' => [], 'inPass' => null];
        }

        $names = District::query()->pluck('name', 'id');

        return [
            'activity' => $activity,
            'remaining' => max(0, (int) now()->diffInSeconds($activity->pass_ends_at, false)),
            'route' => array_map(static fn ($id): string => (string) ($names[$id] ?? '?'), (array) $activity->route),
            'inPass' => (string) ($names[$character->district_id] ?? ''),
        ];
    }
}
