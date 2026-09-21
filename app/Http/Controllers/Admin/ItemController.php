<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Admin\DeletionImpact;
use App\Decks\Hardware;
use App\Decks\HardwareEditor;
use App\Decks\SlotLayout;
use App\Decks\SlotType;
use App\Drugs\DrugEditor;
use App\Drugs\Family;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ItemRequest;
use App\Market\Rarity;
use App\Models\Item;
use App\Models\Skill;
use App\Programs\ProgramEditor;
use App\Programs\ProgramKind;
use App\Support\Text;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The item creator and editor.
 *
 * Item definitions are rows rather than config — the documented exception —
 * which means they get none of the protection config content gets from tests
 * and review. This screen is where that protection has to come from instead,
 * so the validation in App\Http\Requests\Admin\ItemRequest is the substantive
 * part and this class is mostly plumbing around it.
 *
 * An item is no longer always one row. A cyberdeck is a row plus a socket
 * each in `deck_slots`; a module is a row plus its spec in `deck_modules`.
 * Everything that writes more than one table does it in a transaction, and
 * App\Decks\HardwareEditor is the only thing that decides which tables those
 * are — an item cannot be a deck and a module at once, and clearing the side
 * it stopped being is half of what saving one means.
 */
class ItemController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'container' => ['nullable', 'string'],
            'market' => ['nullable', 'string'],
            'hardware' => ['nullable', 'string'],
            'program' => ['nullable', 'string'],
        ]);

        $items = Item::query()
            // `program` eager-loaded for the same reason `deckModule` is: the
            // listing draws a spec line from it for every row that has one, and
            // a query per row is a query per row.
            ->with(['useSkill:id,slug,title', 'marketStock', 'deckSlots', 'deckModule', 'program'])
            ->when($filters['q'] ?? null, fn ($query, string $term) => $query->where(
                fn ($inner) => $inner
                    ->where('slug', 'like', "%{$term}%")
                    ->orWhere('name', 'like', "%{$term}%")
            ))
            ->when(
                in_array($filters['container'] ?? null, Item::CONTAINERS, true),
                fn ($query) => $query->inContainer($filters['container'])
            )
            ->when(
                Hardware::tryFrom((string) ($filters['hardware'] ?? '')),
                fn ($query, Hardware $hardware) => $query->where('hardware', $hardware->value)
            )
            ->when(
                ProgramKind::tryFrom((string) ($filters['program'] ?? '')),
                fn ($query, ProgramKind $kind) => $query->whereHas(
                    'program',
                    fn ($inner) => $inner->where('kind', $kind->value),
                )
            )
            ->when(($filters['market'] ?? null) === 'on', fn ($query) => $query->onMarket())
            ->when(($filters['market'] ?? null) === 'off', fn ($query) => $query->where('on_market', false))
            ->orderBy('slug')
            ->paginate(25)
            ->withQueryString();

        return view('admin.items.index', [
            'items' => $items,
            'filters' => $filters,
            'programKinds' => ProgramKind::cases(),
            'drugFamilies' => Family::cases(),
        ]);
    }

    public function create(): View
    {
        return view('admin.items.form', $this->formData(null));
    }

    public function store(ItemRequest $request): RedirectResponse
    {
        $item = DB::transaction(function () use ($request): Item {
            $item = Item::create($request->columns());

            ProgramEditor::apply($item, $request->programKind(), $request->programColumns());

            DrugEditor::apply($item, $request->drugFamily(), $request->drugColumns());

            HardwareEditor::apply(
                $item,
                $request->hardware(),
                $request->slotLayout(),
                $request->moduleColumns(),
            );

            return $item;
        });

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
        DB::transaction(function () use ($request, $item): void {
            $item->update($request->columns());

            // Always, for the same reason HardwareEditor is: an item that has
            // stopped being software has to LOSE its program row, or it stays
            // runnable by anyone who already owns a copy.
            ProgramEditor::apply($item, $request->programKind(), $request->programColumns());

            // Always, and for the same reason: an item that has stopped being
            // a drug has to lose its row, or it stays swallowable.
            DrugEditor::apply($item, $request->drugFamily(), $request->drugColumns());

            // Always, even for an item that is not hardware and never was:
            // becoming nothing is a change too, and it is the one that has to
            // take the old sockets with it.
            HardwareEditor::apply(
                $item,
                $request->hardware(),
                $request->slotLayout(),
                $request->moduleColumns(),
            );
        });

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
            'hardwareKinds' => Hardware::cases(),
            'programKinds' => ProgramKind::cases(),
            'drugFamilies' => Family::cases(),
            // Every socket a deck could present, which is what the counters
            // in the form are: fourteen numbers, one per type.
            'slotTypes' => SlotType::all(),
            'layout' => $item?->slotLayout() ?? SlotLayout::empty(),
            'module' => $item?->deckModule,
            'impact' => $item === null ? null : DeletionImpact::forItem($item),
        ];
    }
}
