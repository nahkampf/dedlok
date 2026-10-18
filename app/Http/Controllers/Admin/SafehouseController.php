<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SafehouseRequest;
use App\Models\Character;
use App\Models\District;
use App\Models\InventoryItem;
use App\Models\Safehouse;
use App\Support\Text;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\View\View;

/**
 * The safehouse editor. A type belongs to one district and several characters may hold it. Deleting one sends its
 * holders home to the fallback (`characters.safehouse_id` nulls); the fallback itself is never deleted.
 */
class SafehouseController extends Controller
{
    public function index(): View
    {
        return view('admin.safehouses.index', [
            'safehouses' => Safehouse::query()->with('district')->orderBy('title')->get(),
            'holders' => Character::query()->whereNotNull('safehouse_id')->selectRaw('safehouse_id, count(*) as n')->groupBy('safehouse_id')->pluck('n', 'safehouse_id'),
            'fallback' => (string) Config::get('safehouse.fallback'),
        ]);
    }

    public function create(): View
    {
        return view('admin.safehouses.form', $this->formData(null));
    }

    public function store(SafehouseRequest $request): RedirectResponse
    {
        $safehouse = Safehouse::create($request->columns());

        return redirect()->route('admin.safehouses.edit', $safehouse)->with('status', Text::get('admin.safehouses.created'));
    }

    public function edit(Safehouse $safehouse): View
    {
        return view('admin.safehouses.form', $this->formData($safehouse));
    }

    public function update(SafehouseRequest $request, Safehouse $safehouse): RedirectResponse
    {
        $safehouse->update($request->columns());

        return back()->with('status', Text::get('admin.safehouses.updated'));
    }

    public function destroy(Safehouse $safehouse): RedirectResponse
    {
        if ($safehouse->slug === (string) Config::get('safehouse.fallback')) {
            return back()->with('error', Text::get('admin.safehouses.is_fallback'));
        }

        if (InventoryItem::query()->where('safehouse_id', $safehouse->getKey())->exists()) {
            return back()->with('error', Text::get('admin.safehouses.holds_things'));
        }

        $safehouse->delete();

        return redirect()->route('admin.safehouses.index')->with('status', Text::get('admin.safehouses.deleted'));
    }

    /** @return array<string, mixed> */
    private function formData(?Safehouse $safehouse): array
    {
        return [
            'safehouse' => $safehouse,
            'districts' => District::query()->orderBy('name')->get(['id', 'name']),
            'holders' => $safehouse === null ? 0 : Character::query()->where('safehouse_id', $safehouse->getKey())->count(),
        ];
    }
}
