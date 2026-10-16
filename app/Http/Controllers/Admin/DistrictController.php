<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\City\DistrictColor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DistrictRequest;
use App\Models\Character;
use App\Models\District;
use App\Support\Text;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The district editor: the row, and which other districts it links to.
 *
 * Links are stored once, lower id first, and replaced wholesale for the one
 * district being edited. Deleting is refused while anybody stands in the
 * district or it is where new characters begin; a trip whose route names a
 * deleted district stops where the traveller is (`Traveller::settle()`).
 */
class DistrictController extends Controller
{
    public function index(): View
    {
        return view('admin.districts.index', [
            'districts' => District::query()->withCount('locations')->orderBy('name')->get(),
            'standing' => Character::query()->selectRaw('district_id, count(*) as n')->groupBy('district_id')->pluck('n', 'district_id'),
        ]);
    }

    public function create(): View
    {
        return view('admin.districts.form', $this->formData(null));
    }

    public function store(DistrictRequest $request): RedirectResponse
    {
        $district = DB::transaction(function () use ($request): District {
            $district = District::create($request->columns());
            $this->syncLinks($district, $request->linkIds());

            return $district;
        });

        return redirect()->route('admin.districts.edit', $district)->with('status', Text::get('admin.districts.created'));
    }

    public function edit(District $district): View
    {
        return view('admin.districts.form', $this->formData($district));
    }

    public function update(DistrictRequest $request, District $district): RedirectResponse
    {
        DB::transaction(function () use ($request, $district): void {
            $district->update($request->columns());
            $this->syncLinks($district, $request->linkIds());
            $this->movePins($district, (string) $request->input('positions'));
        });

        return back()->with('status', Text::get('admin.districts.updated'));
    }

    public function destroy(District $district): RedirectResponse
    {
        if ($district->slug === (string) Config::get('city.start')) {
            return back()->with('error', Text::get('admin.districts.is_start'));
        }

        if (Character::query()->where('district_id', $district->getKey())->exists()) {
            return back()->with('error', Text::get('admin.districts.occupied'));
        }

        $district->delete();

        return redirect()->route('admin.districts.index')->with('status', Text::get('admin.districts.deleted'));
    }

    /** @return array<string, mixed> */
    private function formData(?District $district): array
    {
        return [
            'district' => $district,
            'colors' => DistrictColor::cases(),
            'others' => District::query()->when($district, fn ($q) => $q->whereKeyNot($district->getKey()))->orderBy('name')->get(['id', 'name']),
            'linked' => $district === null ? [] : collect(District::links())
                ->filter(fn (array $pair): bool => in_array($district->getKey(), $pair, true))
                ->map(fn (array $pair): int => $pair[0] === $district->getKey() ? $pair[1] : $pair[0])
                ->values()->all(),
        ];
    }

    /** The map's drag editor posts {locationId: [x, y]}; clamped to the editor's 25–75 and kept to this district. */
    private function movePins(District $district, string $json): void
    {
        foreach ((array) json_decode($json, true) as $id => $at) {
            if (! is_array($at) || count($at) !== 2) {
                continue;
            }

            $district->locations()->whereKey((int) $id)->update([
                'map_x' => (int) max(25, min(75, round((float) $at[0]))),
                'map_y' => (int) max(25, min(75, round((float) $at[1]))),
            ]);
        }
    }

    /** @param  list<int>  $ids */
    private function syncLinks(District $district, array $ids): void
    {
        $id = (int) $district->getKey();

        DB::table('district_links')->where('district_a_id', $id)->orWhere('district_b_id', $id)->delete();

        foreach ($ids as $other) {
            DB::table('district_links')->insert(['district_a_id' => min($id, $other), 'district_b_id' => max($id, $other)]);
        }
    }
}
