<?php

declare(strict_types=1);

namespace App\Admin;

use App\Decks\Hardware;
use App\Models\Item;
use App\Support\Text;

/**
 * Every item, labelled as a player would see it and grouped by what it is.
 *
 * The list two editors need: the archetype loadout picks what a role starts
 * with, and the loyalty shelf picks what an entity will hand over. It was
 * private to the first of those until the second wanted the identical list,
 * which is the moment to move it rather than copy it — a second grouping that
 * drifted would put an imprint in one bucket on one screen and another on the
 * next.
 *
 * `displayName()` rather than the column, so the hundreds of imprints read as
 * the skills they teach instead of as a list of blanks — which means the sort
 * has to happen after the names exist, not in SQL.
 *
 * The groups are the item's own vocabulary rather than a new one: deck
 * hardware by its `hardware` kind, then the two inventories for everything
 * else. Imprints come out of the data inventory and go last, because there is
 * one per skill and they would otherwise bury every other choice.
 */
final class ItemOptions
{
    /** @return array<string, list<array{id: int, label: string}>> */
    public static function grouped(): array
    {
        $items = Item::query()
            ->with('useSkill:id,title')
            ->get(['id', 'slug', 'name', 'use_op', 'use_skill_id', 'container', 'hardware'])
            ->sortBy(fn (Item $item): string => $item->displayName());

        $groups = [];

        foreach ($items as $item) {
            $groups[self::groupFor($item)][] = [
                'id' => $item->id,
                'label' => "{$item->displayName()} ({$item->slug})",
            ];
        }

        // Ordered here rather than by however the rows arrived: the generic
        // buckets first because most items are in them, hardware next, and the
        // books at the bottom. Empty groups never reach the markup.
        return array_filter(array_merge(array_fill_keys(self::order(), []), $groups));
    }

    /** Which optgroup an item belongs in. */
    private static function groupFor(Item $item): string
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
    private static function order(): array
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
