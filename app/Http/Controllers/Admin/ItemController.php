<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Admin\DeletionImpact;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ItemRequest;
use App\Market\Rarity;
use App\Models\Item;
use App\Models\Skill;
use App\Support\Text;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The item creator and editor.
 *
 * Item definitions are rows rather than config — the documented exception —
 * which means they get none of the protection config content gets from tests
 * and review. This screen is where that protection has to come from instead,
 * so the validation in App\Http\Requests\Admin\ItemRequest is the substantive
 * part and this class is mostly plumbing around it.
 */
class ItemController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'container' => ['nullable', 'string'],
            'market' => ['nullable', 'string'],
        ]);

        $items = Item::query()
            ->with(['useSkill:id,slug,title', 'marketStock'])
            ->when($filters['q'] ?? null, fn ($query, string $term) => $query->where(
                fn ($inner) => $inner
                    ->where('slug', 'like', "%{$term}%")
                    ->orWhere('name', 'like', "%{$term}%")
            ))
            ->when(
                in_array($filters['container'] ?? null, Item::CONTAINERS, true),
                fn ($query) => $query->inContainer($filters['container'])
            )
            ->when(($filters['market'] ?? null) === 'on', fn ($query) => $query->onMarket())
            ->when(($filters['market'] ?? null) === 'off', fn ($query) => $query->where('on_market', false))
            ->orderBy('slug')
            ->paginate(25)
            ->withQueryString();

        return view('admin.items.index', [
            'items' => $items,
            'filters' => $filters,
        ]);
    }

    public function create(): View
    {
        return view('admin.items.form', $this->formData(null));
    }

    public function store(ItemRequest $request): RedirectResponse
    {
        $item = Item::create($request->columns());

        // To the editor rather than the list: the thing that was just made is
        // almost always the thing about to be adjusted.
        return redirect()
            ->route('admin.items.edit', $item)
            ->with('status', Text::get('admin.items.created', ['item' => $item->slug]));
    }

    public function edit(Item $item): View
    {
        return view('admin.items.form', $this->formData($item));
    }

    public function update(ItemRequest $request, Item $item): RedirectResponse
    {
        $item->update($request->columns());

        return back()->with('status', Text::get('admin.items.updated', ['item' => $item->slug]));
    }

    /**
     * Destroy the definition, and with it everything the schema cascades.
     *
     * No guard on what it reaches. That is the decision this screen was built
     * on: an admin who asks for a delete gets one, and the confirmation says
     * what it costs. Nothing here is recoverable, so the impact is counted
     * again on the way past and reported in the flash.
     */
    public function destroy(Item $item): RedirectResponse
    {
        $impact = DeletionImpact::forItem($item);
        $slug = $item->slug;

        $item->delete();

        return redirect()
            ->route('admin.items.index')
            ->with('status', Text::get('admin.items.deleted', [
                'item' => $slug,
                'rows' => array_sum($impact->counts()),
            ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(?Item $item): array
    {
        return [
            'item' => $item,
            'containers' => Item::CONTAINERS,
            'rarities' => Rarity::cases(),
            'useOps' => Item::USE_OPS,
            'maxTier' => Item::MAX_TIER,
            // Every skill, so a book can be pointed at one. Small enough to
            // be a <select>; when it is not, this becomes a search field.
            'skills' => Skill::orderBy('title')->get(['id', 'slug', 'title']),
            'impact' => $item === null ? null : DeletionImpact::forItem($item),
        ];
    }
}
