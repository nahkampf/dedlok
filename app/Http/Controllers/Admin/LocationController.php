<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\City\Concealment;
use App\City\DiscoveryRule;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LocationRequest;
use App\Models\District;
use App\Models\Location;
use App\Support\Text;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * The location editor. A delete takes every character's discovery of it too
 * (`character_locations` cascades); the confirmation is the only brake, as
 * everywhere in the admin.
 */
class LocationController extends Controller
{
    public function index(): View
    {
        return view('admin.locations.index', [
            'locations' => Location::query()->with('district:id,name')->orderBy('district_id')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.locations.form', $this->formData(null));
    }

    public function store(LocationRequest $request): RedirectResponse
    {
        $location = Location::create($request->columns());

        return redirect()->route('admin.locations.edit', $location)->with('status', Text::get('admin.locations.created'));
    }

    public function edit(Location $location): View
    {
        return view('admin.locations.form', $this->formData($location));
    }

    public function update(LocationRequest $request, Location $location): RedirectResponse
    {
        $location->update($request->columns());

        return back()->with('status', Text::get('admin.locations.updated'));
    }

    public function destroy(Location $location): RedirectResponse
    {
        $location->delete();

        return redirect()->route('admin.locations.index')->with('status', Text::get('admin.locations.deleted'));
    }

    /** @return array<string, mixed> */
    private function formData(?Location $location): array
    {
        return [
            'location' => $location,
            'districts' => District::query()->orderBy('name')->get(['id', 'name']),
            'rules' => DiscoveryRule::cases(),
            'concealments' => Concealment::cases(),
        ];
    }
}
