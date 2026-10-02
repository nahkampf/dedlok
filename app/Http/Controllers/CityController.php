<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\District;
use App\Models\Location;
use App\Support\Text;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The City: a map of districts, and behind each one a map of its places.
 *
 * Both are looking, in phase 1 — nothing here moves a body or spends anything.
 * They still refuse a jacked-in character: the City is the physical half, and
 * you must jack out to be in it. Any district's map may be opened from anywhere;
 * what a character can SEE in it is `Location::scopeVisibleTo()` and nothing
 * else, and no count of what is hidden is ever drawn.
 */
class CityController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $character = $request->user()->character()->firstOrFail();

        if ($character->isJackedIn()) {
            return redirect()->route('cyberspace.index')->with('error', Text::get('city.jack_out_first'));
        }

        return view('city.index', [
            'character' => $character,
            'districts' => District::query()->orderBy('name')->get(),
            'links' => District::links(),
        ]);
    }

    public function district(Request $request, District $district): View|RedirectResponse
    {
        $character = $request->user()->character()->firstOrFail();

        if ($character->isJackedIn()) {
            return redirect()->route('cyberspace.index')->with('error', Text::get('city.jack_out_first'));
        }

        return view('city.district', [
            'character' => $character,
            'district' => $district,
            'locations' => Location::query()
                ->where('district_id', $district->getKey())
                ->visibleTo($character)
                ->orderBy('name')
                ->get(),
        ]);
    }
}
