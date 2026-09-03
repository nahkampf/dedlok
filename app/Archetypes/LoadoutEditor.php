<?php

declare(strict_types=1);

namespace App\Archetypes;

use App\Models\Archetype;

/**
 * The only writer of an archetype's two loadout tables.
 *
 * Both are replaced wholesale, for the same reason a skill's prerequisites and
 * a deck's sockets are: the form submits the set it wants rather than a list
 * of changes, and nothing anywhere references a loadout row's id. A diff would
 * be more code for an identical result.
 *
 * That stays true only while a grant is write-once. If a loadout row ever
 * acquires something worth preserving — a note, an order, a flag saying a
 * character has already been given it — this has to become a diff, exactly as
 * the deck sockets will when a module can be fitted into one.
 *
 * Always call it inside a transaction: it writes three tables with the row
 * itself.
 */
final class LoadoutEditor
{
    /**
     * @param  list<array{skill_id: int, level: int}>  $skills
     * @param  list<array{item_id: int, quantity: int}>  $items
     */
    public static function apply(Archetype $archetype, array $skills, array $items): void
    {
        $archetype->startingSkills()->delete();
        $archetype->startingItems()->delete();

        if ($skills !== []) {
            $archetype->startingSkills()->createMany($skills);
        }

        if ($items !== []) {
            $archetype->startingItems()->createMany($items);
        }

        // The archetype was very likely read before this ran, and its loaded
        // relations now describe the loadout as it was.
        $archetype->unsetRelation('startingSkills')->unsetRelation('startingItems');

        // The roster is memoised for the whole request and hands out the
        // instances these rows hang off. A mass delete fires no model events,
        // so the hooks on ArchetypeSkill and ArchetypeItem cannot be relied on
        // to have dropped it — an edit that only removes rows would otherwise
        // leave a cached archetype describing the loadout it used to have.
        Archetype::forgetRoster();
    }
}
