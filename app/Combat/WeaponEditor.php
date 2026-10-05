<?php

declare(strict_types=1);

namespace App\Combat;

use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Weapon;

/**
 * The only writer of the `weapons` table.
 *
 * `DrugEditor`'s twin, with the same call: **it always clears the side an item stopped being.**
 * An item re-saved with no class loses its weapon row, because a key merely absent from an update
 * would leave the old one sitting there.
 *
 * It also keeps what is HELD honest. A weapon that stops being one is put down by everybody
 * holding it; one whose class moves between Ranged and Close is put down too, because the hand it
 * was in no longer fits it.
 */
final class WeaponEditor
{
    /** @param  array<string, mixed>|null  $columns  everything but item_id; null means this is not a weapon */
    public static function apply(Item $item, ?array $columns): void
    {
        $rows = InventoryItem::query()->where('item_id', $item->getKey())->pluck('id');

        if ($columns === null) {
            // Rounds in a magazine go back to their owners first: the row they sit on is the weapon's.
            WeaponLoader::emptyEveryCopyOf($item);
            $item->weapon()->delete();
            self::putDown($rows->all(), ranged: true, melee: true);
            $item->unsetRelation('weapon');

            return;
        }

        $before = $item->weapon?->class;

        // A magazine holds rounds of the old calibre, or none at all if this is no longer a firearm.
        if ($item->weapon !== null && $item->weapon->calibre !== ($columns['calibre'] ?? null)) {
            WeaponLoader::emptyEveryCopyOf($item);
        }

        Weapon::updateOrCreate(['item_id' => $item->getKey()], $columns);

        $class = $columns['class'] instanceof WeaponClass ? $columns['class'] : WeaponClass::from((string) $columns['class']);

        if ($before !== null && $before->ranged() !== $class->ranged()) {
            self::putDown($rows->all(), ranged: $before->ranged(), melee: ! $before->ranged());
        }

        $item->unsetRelation('weapon');
    }

    /** @param  list<int>  $rowIds */
    private static function putDown(array $rowIds, bool $ranged, bool $melee): void
    {
        if ($rowIds === []) {
            return;
        }

        if ($ranged) {
            Character::query()->whereIn('equipped_ranged_id', $rowIds)->update(['equipped_ranged_id' => null]);
        }

        if ($melee) {
            Character::query()->whereIn('equipped_melee_id', $rowIds)->update(['equipped_melee_id' => null]);
        }
    }
}
