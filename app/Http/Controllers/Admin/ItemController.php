<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Admin\DeletionImpact;
use App\Combat\AmmoType;
use App\Combat\AmmunitionEditor;
use App\Combat\ArmourEditor;
use App\Combat\DamageProfile;
use App\Combat\WeaponClass;
use App\Combat\WeaponEditor;
use App\Combat\WearSlot;
use App\Crafting\DesignEditor;
use App\Cyberspace\SystemType;
use App\Decks\Hardware;
use App\Decks\HardwareEditor;
use App\Decks\SlotLayout;
use App\Decks\SlotType;
use App\Drugs\DrugEditor;
use App\Drugs\Family;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ItemRequest;
use App\Items\ConsumableEditor;
use App\Market\Rarity;
use App\Models\Entity;
use App\Models\Item;
use App\Models\Skill;
use App\Programs\ProgramEditor;
use App\Programs\ProgramKind;
use App\Stats\DamageOrigin;
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
            'type' => ['nullable', 'string', 'max:40'],
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
            ->when($filters['type'] ?? null, fn ($query, string $type) => $this->ofType($query, $type))
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
            'typeGroups' => $this->typeGroups(),
        ]);
    }

    /**
     * Every kind of item there is, for the list's one Type filter: value => label, grouped. A value is `kind` or
     * `kind:detail` (deck hardware's piece, software's kind, a drug's family).
     *
     * @return array<string, array<string, string>>
     */
    private function typeGroups(): array
    {
        $groups = [
            Text::get('admin.items.types.hardware') => ['hardware' => Text::get('admin.items.types.any_hardware')]
                + collect(Hardware::cases())->mapWithKeys(fn (Hardware $kind): array => ["hardware:{$kind->value}" => $kind->label()])->all(),
            Text::get('admin.items.types.software') => ['software' => Text::get('admin.items.types.any_software')]
                + collect(ProgramKind::cases())->mapWithKeys(fn (ProgramKind $kind): array => ["software:{$kind->value}" => $kind->label()])->all(),
            Text::get('admin.items.types.drug') => ['drug' => Text::get('admin.items.types.any_drug'), 'drug:fight_only' => Text::get('admin.items.types.fight_only')]
                + collect(Family::cases())->mapWithKeys(fn (Family $family): array => ["drug:{$family->value}" => $family->label()])->all(),
            Text::get('admin.items.types.gear') => [
                'armour' => Text::get('admin.items.types.armour'),
                'weapon' => Text::get('admin.items.types.weapon'),
                'ammunition' => Text::get('admin.items.types.ammunition'),
            ],
            Text::get('admin.items.types.other') => [
                'consumable' => Text::get('admin.items.types.consumable'),
                'implant' => Text::get('admin.items.types.implant'),
                'imprint' => Text::get('admin.items.types.imprint'),
                'plain' => Text::get('admin.items.types.plain'),
            ],
        ];

        return $groups;
    }

    /** Narrow a list to one kind of item. An unknown value narrows nothing, like every other filter here. */
    private function ofType($query, string $type)
    {
        [$kind, $detail] = array_pad(explode(':', $type, 2), 2, null);

        return match ($kind) {
            'hardware' => $detail === null ? $query->whereNotNull('hardware') : $query->where('hardware', $detail),
            'software' => $query->whereHas('program', fn ($inner) => $detail === null ? $inner : $inner->where('kind', $detail)),
            'drug' => $query->whereHas('drug', fn ($inner) => match (true) {
                $detail === null => $inner,
                $detail === 'fight_only' => $inner->where('fight_only', true),
                default => $inner->where('family', $detail),
            }),
            'armour', 'weapon', 'ammunition', 'consumable' => $query->whereHas($kind),
            'implant' => $query->whereNotNull('implant_region'),
            'imprint' => $query->where('use_op', Item::USE_INJECT_SKILL),
            // Nothing but an item: none of the kinds above.
            'plain' => $query->whereNull('hardware')->whereNull('implant_region')->where(fn ($inner) => $inner->whereNull('use_op')->orWhere('use_op', '!=', Item::USE_INJECT_SKILL))
                ->whereDoesntHave('program')->whereDoesntHave('drug')->whereDoesntHave('consumable')
                ->whereDoesntHave('armour')->whereDoesntHave('weapon')->whereDoesntHave('ammunition'),
            default => $query,
        };
    }

    /**
     * `?clone={id}` opens the form as a NEW item filled from an existing one: an unsaved copy with the
     * original's related rows carried over, and no slug (a slug is unique and permanent, so it is typed).
     */
    public function create(Request $request): View
    {
        $source = Item::query()->find($request->integer('clone'));

        if ($source === null) {
            return view('admin.items.form', $this->formData(null));
        }

        $source->load(['deckSlots', 'deckModule', 'program', 'drug', 'consumable', 'armour', 'weapon', 'ammunition', 'design.components']);
        $copy = $source->replicate();
        $copy->slug = '';
        $copy->setRelations($source->getRelations());

        return view('admin.items.form', $this->formData($copy));
    }

    public function store(ItemRequest $request): RedirectResponse
    {
        $item = DB::transaction(function () use ($request): Item {
            $item = Item::create($request->columns());

            ProgramEditor::apply($item, $request->programKind(), $request->programColumns());

            DesignEditor::apply($item, $request->designProductId(), $request->designColumns(), $request->designLines());

            DrugEditor::apply($item, $request->drugFamily(), $request->drugColumns());

            ConsumableEditor::apply($item, $request->consumableEffects(), $request->consumableUsableInFight());

            ArmourEditor::apply($item, $request->armourSlots());
            WeaponEditor::apply($item, $request->weaponColumns());
            AmmunitionEditor::apply($item, $request->ammoColumns());

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

            // Always: an item that has stopped being a design loses its recipe.
            DesignEditor::apply($item, $request->designProductId(), $request->designColumns(), $request->designLines());

            // Always, and for the same reason: an item that has stopped being
            // a drug has to lose its row, or it stays swallowable.
            DrugEditor::apply($item, $request->drugFamily(), $request->drugColumns());

            // Always, for the same reason: an item that has stopped being a consumable has to lose its row, or it
            // stays usable by anyone who already owns a copy.
            ConsumableEditor::apply($item, $request->consumableEffects(), $request->consumableUsableInFight());

            // Always: an item that has stopped being armour has to lose its row, and come off
            // everybody wearing it.
            ArmourEditor::apply($item, $request->armourSlots());

            // Always, for the same reason: stopping being a weapon or a round clears the row (and
            // puts a weapon down in every hand holding it).
            WeaponEditor::apply($item, $request->weaponColumns());
            AmmunitionEditor::apply($item, $request->ammoColumns());

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
            // Every skill, so an imprint can be pointed at one. Small enough to
            // be a <select>; when it is not, this becomes a search field.
            'skills' => Skill::orderBy('title')->get(['id', 'slug', 'title']),
            'hardwareKinds' => Hardware::cases(),
            'programKinds' => ProgramKind::cases(),
            // The ladder a Decryptor can be limited to.
            'encryptions' => Skill::query()->where('category', 'Encryption')->orderBy('rank')->get(['slug', 'title']),
            'drugFamilies' => Family::cases(),
            // A design's pickers: the programs it can make, the data items it can ask for, and the origin filters.
            'designProducts' => Item::query()->whereHas('program', fn ($q) => $q->whereIn('kind', [...array_keys((array) config('crafting.gate')), ...(array) config('crafting.ungated', [])]))->orderBy('name')->get(['id', 'name']),
            'designComponents' => Item::query()->where('container', Item::CONTAINER_DATA)->whereDoesntHave('program')->orderBy('name')->get(['id', 'name']),
            'operatingSystems' => Skill::query()->where('category', 'Operating Systems')->orderBy('title')->get(['slug', 'title']),
            'owners' => Entity::query()->orderBy('name')->get(['id', 'name']),
            'systemTypes' => SystemType::cases(),
            'armourSlots' => WearSlot::cases(),
            'weaponClasses' => WeaponClass::cases(),
            'ammoTypes' => AmmoType::cases(),
            // The eight damage types, as value => label, for the two profile grids.
            'damageTypes' => array_combine(DamageProfile::types(), array_map(
                static fn (DamageOrigin $type): string => $type->label(),
                DamageOrigin::types(),
            )),
            // Every socket a deck could present, which is what the counters
            // in the form are: fourteen numbers, one per type.
            'slotTypes' => SlotType::all(),
            'layout' => $item?->slotLayout() ?? SlotLayout::empty(),
            'module' => $item?->deckModule,
            'impact' => $item === null || ! $item->exists ? null : DeletionImpact::forItem($item),
        ];
    }
}
