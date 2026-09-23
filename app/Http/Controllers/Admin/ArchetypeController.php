<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Archetypes\LoadoutEditor;
use App\Decks\Hardware;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ArchetypeRequest;
use App\Models\Archetype;
use App\Models\Item;
use App\Models\Skill;
use App\Skills\SkillProgression;
use App\Support\Text;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The archetype editor: who each starting role is, and what it hands out.
 *
 * Three routes rather than seven. There is no create and no destroy, and that
 * is the design rather than an omission: `characters.archetype` stores the id,
 * so an archetype cannot be deleted without re-rolling everyone who chose it,
 * and a new one cannot be made without hand-picking an id that is permanent
 * from the moment it is saved. The roster is inserted by its migration for the
 * same reason. When a new archetype is genuinely wanted, it arrives the way
 * the seven did — as a migration, with its id written down.
 *
 * Perks are shown here and edited elsewhere, which is to say nowhere yet: a
 * live perk is a list of modifiers with a target shape of its own, and that is
 * an editor in its own right rather than a corner of this one.
 */
class ArchetypeController extends Controller
{
    public function index(): View
    {
        return view('admin.archetypes.index', [
            // Seven rows and a hard ceiling on how many there can be, so the
            // whole roster, unpaginated, with the counts the table shows.
            'archetypes' => Archetype::query()
                ->withCount(['perks', 'startingSkills', 'startingItems'])
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function edit(Archetype $archetype): View
    {
        return view('admin.archetypes.form', $this->formData($archetype));
    }

    public function update(ArchetypeRequest $request, Archetype $archetype): RedirectResponse
    {
        DB::transaction(function () use ($request, $archetype): void {
            $archetype->update($request->columns());

            // Both sides of the loadout, always — a save that removed every
            // starting item has to write that too, and LoadoutEditor is the
            // only thing that touches those tables.
            LoadoutEditor::apply($archetype, $request->skills(), $request->items());
        });

        return back()->with('status', Text::get('admin.archetypes.updated', [
            'archetype' => $archetype->slug,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Archetype $archetype): array
    {
        $archetype->load([
            'perks' => fn ($query) => $query->orderBy('position')->orderBy('id'),
            'startingSkills.skill:id,slug,title',
            'startingItems.item.useSkill:id,title',
        ]);

        return [
            'archetype' => $archetype,
            'maxLevel' => SkillProgression::fromConfig()->maxLevel(),
            // Every skill and every item, so a loadout can name one, grouped
            // into optgroups because a flat list of every imprint in the
            // game is a scroll rather than a choice. Still <select>s: when
            // grouping stops being enough, this becomes a search field.
            'skills' => $this->skillOptions(),
            'items' => $this->itemOptions(),
            'grantedSkills' => $archetype->startingSkills
                ->sortBy(fn ($grant) => $grant->skill?->title ?? '')
                ->values(),
            'grantedItems' => $archetype->startingItems
                ->sortBy(fn ($grant) => $grant->item?->displayName() ?? '')
                ->values(),
        ];
    }

    /**
     * Every skill, grouped by its category.
     *
     * The same grouping the skill tree draws, and shown the same way: the
     * category is printed raw, because it is created by being typed into the
     * skill editor and there is nowhere else its display name could live.
     *
     * @return array<string, list<array{id: int, label: string}>>
     */
    private function skillOptions(): array
    {
        return Skill::query()
            ->orderBy('category')
            ->orderBy('title')
            ->get(['id', 'slug', 'title', 'category'])
            ->groupBy('category')
            ->map(fn (Collection $skills): array => $skills
                ->map(fn (Skill $skill): array => [
                    'id' => $skill->id,
                    'label' => "{$skill->title} ({$skill->slug})",
                ])
                ->all())
            ->all();
    }

    /**
     * Every item, labelled the way a player would see it and grouped by what
     * kind of thing it is.
     *
     * displayName() rather than the column, so the hundreds of imprints read
     * as the skills they teach instead of as a list of blanks — which means
     * the sort has to happen after the names exist, not in SQL.
     *
     * The groups are the item's own vocabulary rather than a new one: deck
     * hardware by its `hardware` kind, then the two inventories for everything
     * else. Imprints come out of the data inventory and last, because there
     * is one per skill and they would otherwise bury every other choice.
     *
     * @return array<string, list<array{id: int, label: string}>>
     */
    private function itemOptions(): array
    {
        $items = Item::query()
            ->with('useSkill:id,title')
            ->get(['id', 'slug', 'name', 'use_op', 'use_skill_id', 'container', 'hardware'])
            ->sortBy(fn (Item $item): string => $item->displayName());

        $groups = [];

        foreach ($items as $item) {
            $groups[$this->itemGroup($item)][] = [
                'id' => $item->id,
                'label' => "{$item->displayName()} ({$item->slug})",
            ];
        }

        // Ordered here rather than by however the rows arrived: the generic
        // buckets first because most items are in them, hardware next, and the
        // books at the bottom. Empty groups never reach the markup.
        return array_filter(array_merge(
            array_fill_keys($this->groupOrder(), []),
            $groups,
        ));
    }

    /** Which optgroup an item belongs in. */
    private function itemGroup(Item $item): string
    {
        if ($item->isImprint()) {
            return Text::get('admin.archetypes.items.groups.imprints');
        }

        if ($item->hardware !== null) {
            return $item->hardware->label();
        }

        return Text::get('admin.archetypes.items.groups.'.$item->container);
    }

    /**
     * The order the groups are offered in.
     *
     * @return list<string>
     */
    private function groupOrder(): array
    {
        return [
            Text::get('admin.archetypes.items.groups.physical'),
            Text::get('admin.archetypes.items.groups.data'),
            ...array_map(
                static fn (Hardware $hardware): string => $hardware->label(),
                Hardware::cases(),
            ),
            Text::get('admin.archetypes.items.groups.imprints'),
        ];
    }
}
