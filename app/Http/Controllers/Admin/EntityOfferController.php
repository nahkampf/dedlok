<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Admin\ItemOptions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EntityOfferRequest;
use App\Models\Entity;
use App\Models\EntityOffer;
use App\Standing\Service;
use App\Support\Text;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The loyalty shelf editor: what an entity will hand over, and for how much.
 *
 * ## Scoped to an entity, because a shelf belongs to one
 *
 * There is no flat list of every offer in the game and there should not be:
 * `entity_offers` is meaningless without the entity it belongs to, and a
 * screen listing all of them would be a table whose first column is the only
 * one that matters.
 *
 * ## One screen per shelf, not an edit page per row
 *
 * An offer is four numbers and an item. A row is small enough to edit in
 * place, so the index IS the editor — the same call the other editors make by
 * having no `show` route, arrived at from the other direction.
 *
 * ## Entities are listed, never created or destroyed
 *
 * `entities` arrives by migration for the reason archetypes do: systems point
 * at an owner, and an entity cannot be deleted without orphaning a district of
 * the grid.
 *
 * What CAN be edited here is what they sell and what they say about
 * themselves. `business` and `description` are player-visible flavour with no
 * mechanical weight, and until now a typo in either was unfixable without a
 * migration. `name`, `category` and `interactable` stay with the migration on
 * purpose: the first is referenced by stored sentences in the inbox, and the
 * other two are design decisions that change what an entity IS rather than
 * how it reads.
 */
class EntityOfferController extends Controller
{
    /** Who keeps a shelf, and how much is on it. */
    public function index(): View
    {
        return view('admin.entities.index', [
            // Every entity, not only the interactable ones: a shelf on flavour
            // scenery is a mistake worth being able to SEE rather than one the
            // screen hides. The count is what says which are real.
            'entities' => Entity::query()
                ->withCount('offers')
                ->orderByDesc('interactable')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function edit(Entity $entity): View
    {
        return view('admin.entities.shelf', $this->shelfFor($entity));
    }

    /**
     * What an entity says about itself.
     *
     * Two nullable strings, validated inline rather than in a FormRequest:
     * there is no shape to explain, no CHECK to mirror and no columns() worth
     * extracting — the rules ARE the whole of it.
     *
     * Empty means NULL rather than an empty string. A flavour entity has no
     * long form at all, and '' would be a description that renders as nothing
     * while reading as present.
     */
    public function update(Request $request, Entity $entity): RedirectResponse
    {
        $data = $request->validate([
            'business' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);

        $entity->update([
            'business' => $data['business'] ?? null,
            'description' => $data['description'] ?? null,
        ]);

        return back()->with('status', Text::get('admin.entities.described', [
            'entity' => $entity->name,
        ]));
    }

    public function store(EntityOfferRequest $request, Entity $entity): RedirectResponse
    {
        $offer = $entity->offers()->create($request->columns());

        return back()->with('status', Text::get('admin.entities.added', [
            'item' => $offer->displayName(),
        ]));
    }

    public function updateOffer(EntityOfferRequest $request, Entity $entity, EntityOffer $offer): RedirectResponse
    {
        abort_unless($offer->entity_id === $entity->getKey(), 404);

        $offer->update($request->columns());

        return back()->with('status', Text::get('admin.entities.saved', [
            'item' => $offer->fresh()?->displayName() ?? '',
        ]));
    }

    /**
     * Take a row off the shelf.
     *
     * NOTHING IS COUNTED FIRST, unlike deleting an item or a skill. An offer
     * reaches nothing: it is not pointed at by anything, and what somebody
     * already redeemed is theirs — the inventory row does not know it came
     * from here. So there is no `DeletionImpact` and no dialog.
     */
    public function destroy(Entity $entity, EntityOffer $offer): RedirectResponse
    {
        abort_unless($offer->entity_id === $entity->getKey(), 404);

        $name = $offer->displayName();

        $offer->delete();

        return back()->with('status', Text::get('admin.entities.removed', ['item' => $name]));
    }

    /** @return array<string, mixed> */
    private function shelfFor(Entity $entity): array
    {
        return [
            'entity' => $entity,
            'offers' => $entity->offers()
                ->with('item')
                ->get()
                ->sortBy(fn (EntityOffer $offer): string => $offer->displayName())
                ->values(),
            'items' => ItemOptions::grouped(markOffMarket: true),
            'services' => Service::cases(),
        ];
    }
}
