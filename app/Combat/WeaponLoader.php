<?php

declare(strict_types=1);

namespace App\Combat;

use App\Inventory\ItemGranter;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Puts rounds into a weapon and takes them out. The only writer of `inventory_items.loaded_ammo_id`
 * and `loaded_rounds`.
 *
 * ## One door for all of it
 *
 * Loading, topping up, switching to another type and unloading are one writer, because they are
 * one fact: what is in a magazine. Loading a DIFFERENT round into a magazine that holds some puts
 * the old ones back in the stash first, in the same transaction, so rounds are never in two
 * places or neither.
 *
 * ## Rounds come from the stash and go back to it
 *
 * Taken off the loose pile of the ammunition item (deleting a row that reaches zero) and returned
 * through `ItemGranter::grant()`, which merges — a second merge here would be a second answer.
 * Only LOOSE rows count: ammunition in a container or listed on the market is not in the hands.
 *
 * ## Out of a fight this is free; in one it costs a turn
 *
 * Nothing here knows about turns. The fight engine spends the turn and then calls this: a reload
 * of an EMPTY magazine, or a switch of type at any time, each a whole turn of combat. A top-up of
 * a part-full magazine of the same round is not offered mid-fight — that is the engine's rule,
 * because the loader cannot tell a fight from the stash.
 */
final class WeaponLoader
{
    /**
     * Fill the weapon from the stash with this round, to the magazine's size.
     */
    public function load(Character $character, InventoryItem $weapon, Item $ammo): true|LoadRefusal
    {
        return DB::transaction(function () use ($character, $weapon, $ammo): true|LoadRefusal {
            $row = InventoryItem::query()->lockForUpdate()->with('item.weapon')->find($weapon->getKey());

            if ($row === null || $row->character_id !== $character->id) {
                return LoadRefusal::NotOwned;
            }

            $gun = $row->item?->weapon;

            if ($gun === null || ! $gun->class->firearm()) {
                return LoadRefusal::NotAFirearm;
            }

            if (! InventoryItem::query()->loose()->whereKey($row->getKey())->exists()) {
                return LoadRefusal::NotFree;
            }

            $round = $ammo->ammunition;

            if ($round === null) {
                return LoadRefusal::NotAmmunition;
            }

            if ($round->calibre !== $gun->calibre) {
                return LoadRefusal::WrongCalibre;
            }

            $same = $row->loaded_ammo_id === $ammo->getKey() && $row->rounds() > 0;

            if ($same && $row->rounds() >= (int) $gun->magazine) {
                return LoadRefusal::Full;
            }

            $pile = $this->pile($character, $ammo);
            $room = (int) $gun->magazine - ($same ? $row->rounds() : 0);

            // Asked BEFORE anything moves: returning a refusal does not roll a transaction back, so a
            // swap that cannot finish must not have started. The rounds going back are a different
            // item from the ones coming out (a same-type load never swaps), so they cannot supply it.
            if ($pile->sum('quantity') < 1) {
                return LoadRefusal::NoAmmunition;
            }

            // A different round is swapped for, so what is in there goes back first.
            if (! $same && $row->rounds() > 0) {
                $this->emptyInto($character, $row);
            }

            $taken = $this->take($pile, $room);

            $row->forceFill([
                'loaded_ammo_id' => $ammo->getKey(),
                'loaded_rounds' => ($same ? $row->rounds() : 0) + $taken,
            ])->save();

            return true;
        });
    }

    /**
     * Rounds fired: the magazine is `$n` lighter. For a fight, which plays shots in its own snapshot and
     * reports how many were spent. Refuses to go below nothing — that would mean the fight fired rounds
     * the weapon never held, which is a bug and not an ordinary answer.
     */
    public function spend(Character $character, InventoryItem $weapon, int $n): true|LoadRefusal
    {
        return DB::transaction(function () use ($character, $weapon, $n): true|LoadRefusal {
            $row = InventoryItem::query()->lockForUpdate()->find($weapon->getKey());

            if ($row === null || $row->character_id !== $character->id) {
                return LoadRefusal::NotOwned;
            }

            if ($row->rounds() < $n) {
                return LoadRefusal::NothingLoaded;
            }

            $row->forceFill(['loaded_rounds' => $row->loaded_rounds - $n])->save();

            return true;
        });
    }

    /**
     * Take everything out of the weapon and put it back in the stash.
     */
    public function unload(Character $character, InventoryItem $weapon): true|LoadRefusal
    {
        return DB::transaction(function () use ($character, $weapon): true|LoadRefusal {
            $row = InventoryItem::query()->lockForUpdate()->find($weapon->getKey());

            if ($row === null || $row->character_id !== $character->id) {
                return LoadRefusal::NotOwned;
            }

            if ($row->rounds() < 1) {
                return LoadRefusal::NothingLoaded;
            }

            $this->emptyInto($character, $row);

            return true;
        });
    }

    /**
     * Every copy of a weapon item hands its rounds back to its owner. For `WeaponEditor`, when a
     * weapon stops being a firearm or changes calibre and what is in the magazines no longer fits.
     */
    public static function emptyEveryCopyOf(Item $weaponItem): void
    {
        self::emptyWhere(fn ($q) => $q->where('item_id', $weaponItem->getKey()));
    }

    /**
     * Every weapon loaded with this ammunition item hands it back. For `AmmunitionEditor`, when a
     * round stops being ammunition or changes calibre and no longer fits what it is loaded in.
     */
    public static function emptyEveryWeaponHolding(Item $ammoItem): void
    {
        self::emptyWhere(fn ($q) => $q->where('loaded_ammo_id', $ammoItem->getKey()));
    }

    private static function emptyWhere(callable $scope): void
    {
        DB::transaction(function () use ($scope): void {
            $query = InventoryItem::query()->whereNotNull('loaded_ammo_id')->where('loaded_rounds', '>', 0);
            $scope($query);

            foreach ($query->lockForUpdate()->get() as $row) {
                $owner = Character::query()->find($row->character_id);

                if ($owner !== null) {
                    (new self)->emptyInto($owner, $row);
                }
            }
        });
    }

    /** The rounds in this magazine go back to the owner's stash, and the magazine is empty. */
    public function emptyInto(Character $character, InventoryItem $row): void
    {
        $item = $row->loaded_ammo_id === null ? null : Item::query()->find($row->loaded_ammo_id);

        if ($item !== null && $row->loaded_rounds > 0) {
            ItemGranter::grant($character, $item, (int) $row->loaded_rounds);
        }

        $row->forceFill(['loaded_ammo_id' => null, 'loaded_rounds' => 0])->save();
    }

    /** @return Collection<int, InventoryItem> the loose copies of this round the character holds */
    private function pile(Character $character, Item $ammo): Collection
    {
        return InventoryItem::query()
            ->where('character_id', $character->id)
            ->where('item_id', $ammo->getKey())
            ->loose()
            ->lockForUpdate()
            ->orderBy('id')
            ->get();
    }

    /**
     * Draw up to $want rounds off the pile, deleting a row that reaches zero.
     *
     * @param  Collection<int, InventoryItem>  $pile
     */
    private function take(Collection $pile, int $want): int
    {
        $taken = 0;

        foreach ($pile as $row) {
            $use = min($want - $taken, (int) $row->quantity);

            if ($use < 1) {
                break;
            }

            if ($use === (int) $row->quantity) {
                $row->delete();
            } else {
                $row->forceFill(['quantity' => $row->quantity - $use])->save();
            }

            $taken += $use;
        }

        return $taken;
    }
}
