<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Cyberspace\GridSeeder;
use App\Cyberspace\Subnets;
use App\Cyberspace\SystemType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SubnetRequest;
use App\Models\Entity;
use App\Models\Ice;
use App\Models\System;
use App\Support\Text;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The subnet editor: the grid row, its spec, its gateway pair, and a button that fills it. `Subnets` is the writer.
 */
class SubnetController extends Controller
{
    public function index(): View
    {
        return view('admin.subnets.index', [
            'subnets' => DB::table('grids')->where('id', '>', 1)->orderBy('name')->get(),
            'counts' => System::query()->where('grid_id', '>', 1)->selectRaw('grid_id, count(*) as n')->groupBy('grid_id')->pluck('n', 'grid_id'),
        ]);
    }

    public function create(): View
    {
        return view('admin.subnets.form', $this->formData(null));
    }

    public function store(SubnetRequest $request): RedirectResponse
    {
        $id = Subnets::fromConfig()->create($request->gridColumns(), $request->spec(), $request->iceId(), $request->rulesList());

        if ($id === null) {
            return back()->withInput()->with('error', Text::get('admin.subnets.no_room'));
        }

        return redirect()->route('admin.subnets.edit', $id)->with('status', Text::get('admin.subnets.created'));
    }

    public function edit(int $subnet): View
    {
        return view('admin.subnets.form', $this->formData($subnet));
    }

    public function update(SubnetRequest $request, int $subnet): RedirectResponse
    {
        abort_unless($subnet > 1 && DB::table('grids')->where('id', $subnet)->exists(), 404);

        Subnets::fromConfig()->update($subnet, (string) $request->input('name'), $request->spec(), $request->iceId(), $request->rulesList());

        return back()->with('status', Text::get('admin.subnets.updated'));
    }

    /** Top the subnet up to its spec's occupancy. */
    public function generate(int $subnet): RedirectResponse
    {
        abort_unless($subnet > 1 && DB::table('grids')->where('id', $subnet)->exists(), 404);

        $result = GridSeeder::forGrid($subnet)->run();

        return back()->with('status', Text::get('admin.subnets.generated', ['created' => $result->created, 'percent' => $result->occupancy()]));
    }

    public function destroy(int $subnet): RedirectResponse
    {
        abort_unless($subnet > 1 && DB::table('grids')->where('id', $subnet)->exists(), 404);

        if (! Subnets::fromConfig()->delete($subnet)) {
            return back()->with('error', Text::get('admin.subnets.occupied'));
        }

        return redirect()->route('admin.subnets.index')->with('status', Text::get('admin.subnets.deleted'));
    }

    /** @return array<string, mixed> */
    private function formData(?int $id): array
    {
        $grid = $id === null ? null : (DB::table('grids')->where('id', $id)->where('id', '>', 1)->first() ?? abort(404));
        $gate = $id === null ? null : System::query()->where('grid_id', $id)->where('type', SystemType::Gateway->value)->first();
        $outer = $gate?->linksTo;

        return [
            'grid' => $grid,
            'spec' => $grid === null ? [] : (array) json_decode((string) $grid->spec, true),
            'gate' => $outer,
            'flag' => collect($outer?->entry_rules ?? [])->firstWhere('kind', 'flag')['flag'] ?? '',
            'infiltrate' => collect($outer?->entry_rules ?? [])->contains('kind', 'infiltrate'),
            'ices' => Ice::query()->orderBy('level')->get(['id', 'level', 'name']),
            'entities' => Entity::query()->orderBy('name')->get(['id', 'name']),
            'types' => SystemType::ordinary(),
            'count' => $id === null ? 0 : System::query()->where('grid_id', $id)->count(),
        ];
    }
}
