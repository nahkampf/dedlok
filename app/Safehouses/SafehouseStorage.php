<?php

declare(strict_types=1);

namespace App\Safehouses;

use App\Decks\Storage;
use App\Models\Character;
use App\Models\CityActivity;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Safehouse;
use App\Notifications\Category;
use App\Notifications\Notifier;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of `inventory_items.safehouse_id` and `storage_expires_at`. docs/FATIGUE.md, *Safehouses*.
 *
 * A stored row is an ordinary inventory row tagged with the safehouse: out of the stash (`scopeLoose`), with no stack
 * key (piles are merged by hand here), and counted against the type's `storage_slots`, one per row. Money and combat
 * only ever take creds, so what is stored is safe from a mugging by construction.
 *
 * ## Losing a home
 *
 * Whatever was stored there is given `safehouse.storage_hours` (48) to be collected: the owner is told, may
 * `collect()` what fits in the stash, and `sweep()` deletes the rest. Renting the same place again takes it back.
 */
final class SafehouseStorage
{
    /** Rows stored at a safehouse that are still theirs to use (no deadline running). */
    public function used(Character $character, Safehouse $safehouse): int
    {
        return $this->rows($character, $safehouse)->whereNull('storage_expires_at')->count();
    }

    /** @return Builder<InventoryItem> */
    public function rows(Character $character, Safehouse $safehouse): Builder
    {
        return InventoryItem::query()->where('character_id', $character->getKey())->where('safehouse_id', $safehouse->getKey());
    }

    private function refusal(Character $character, Safehouse $home): ?Refusal
    {
        if ($character->isJackedIn()) {
            return Refusal::JackedIn;
        }

        if ($character->current_fight_id !== null) {
            return Refusal::Fighting;
        }

        if (CityActivity::query()->whereKey($character->getKey())->exists()) {
            return Refusal::Busy;
        }

        return $character->district_id === $home->district_id ? null : Refusal::NotHere;
    }

    /** Put some of a loose row into storage at their home. */
    public function deposit(Character $character, InventoryItem $row, int $quantity = 1): true|Refusal
    {
        return DB::transaction(function () use ($character, $row, $quantity): true|Refusal {
            $locked = Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail();
            $home = $locked->home();

            if ($refusal = $this->refusal($locked, $home)) {
                return $refusal;
            }

            if ($home->storage_slots < 1) {
                return Refusal::NoStorage;
            }

            $source = InventoryItem::query()->loose()->whereKey($row->getKey())->where('character_id', $locked->getKey())->lockForUpdate()->first();

            // The equipped deck is carried, whatever its row says; anything on the person stays on it.
            if ($source === null || $source->isOnPerson() || $locked->equipped_deck_id === $source->getKey()) {
                return Refusal::CannotStore;
            }

            $quantity = max(1, min($quantity, $source->quantity));
            $item = $source->item;
            $twin = $item->stackable
                ? $this->rows($locked, $home)->whereNull('storage_expires_at')->where('item_id', $item->getKey())->lockForUpdate()->first()
                : null;

            if ($twin === null && $this->used($locked, $home) >= $home->storage_slots) {
                return Refusal::StorageFull;
            }

            if ($twin !== null) {
                $twin->forceFill(['quantity' => $twin->quantity + $quantity])->save();
                $this->take($source, $quantity);
            } elseif ($quantity === $source->quantity) {
                $source->forceFill(['safehouse_id' => $home->getKey()])->save();
            } else {
                $source->forceFill(['quantity' => $source->quantity - $quantity])->save();
                InventoryItem::query()->create(['character_id' => $locked->getKey(), 'item_id' => $item->getKey(), 'quantity' => $quantity, 'safehouse_id' => $home->getKey()]);
            }

            return true;
        });
    }

    /** Take some of a stored row back to the stash. Data must fit the deck. */
    public function withdraw(Character $character, InventoryItem $row, int $quantity = 1): true|Refusal
    {
        return DB::transaction(function () use ($character, $row, $quantity): true|Refusal {
            $locked = Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail();
            $home = $locked->home();

            if ($refusal = $this->refusal($locked, $home)) {
                return $refusal;
            }

            $stored = $this->rows($locked, $home)->whereNull('storage_expires_at')->whereKey($row->getKey())->lockForUpdate()->first();

            return $stored === null ? Refusal::CannotStore : $this->release($locked, $stored, $quantity);
        });
    }

    /** Move a stored row (or part of a stack) to the stash. */
    private function release(Character $character, InventoryItem $stored, int $quantity): true|Refusal
    {
        $quantity = max(1, min($quantity, $stored->quantity));
        $item = $stored->item;

        if ($item->container === Item::CONTAINER_DATA && ! Storage::fits($character, Storage::sizeOf($item) * $quantity)) {
            return Refusal::NoRoom;
        }

        $twin = $item->stackable
            ? InventoryItem::query()->loose()->where('character_id', $character->getKey())->where('item_id', $item->getKey())->whereNotNull('stack_key')->lockForUpdate()->first()
            : null;

        if ($twin !== null) {
            $twin->forceFill(['quantity' => $twin->quantity + $quantity])->save();
            $this->take($stored, $quantity);
        } elseif ($quantity === $stored->quantity) {
            $stored->forceFill(['safehouse_id' => null, 'storage_expires_at' => null])->save();
        } else {
            $stored->forceFill(['quantity' => $stored->quantity - $quantity])->save();
            InventoryItem::query()->create(['character_id' => $character->getKey(), 'item_id' => $item->getKey(), 'quantity' => $quantity]);
        }

        return true;
    }

    /** A raider takes a whole stored row for themselves. False when it is data their deck cannot hold. */
    public function seize(Character $to, InventoryItem $stored): bool
    {
        $item = $stored->item;

        if ($item->container === Item::CONTAINER_DATA && ! Storage::fits($to, Storage::sizeOf($item) * $stored->quantity)) {
            return false;
        }

        $twin = $item->stackable
            ? InventoryItem::query()->loose()->where('character_id', $to->getKey())->where('item_id', $item->getKey())->whereNotNull('stack_key')->lockForUpdate()->first()
            : null;

        if ($twin !== null) {
            $twin->forceFill(['quantity' => $twin->quantity + $stored->quantity])->save();
            $stored->delete();
        } else {
            $stored->forceFill(['character_id' => $to->getKey(), 'safehouse_id' => null, 'storage_expires_at' => null])->save();
        }

        return true;
    }

    /** A burnt home: everything stored there is gone at once, no deadline. @return int rows destroyed */
    public function burn(Character $character, Safehouse $home): int
    {
        return $this->rows($character, $home)->delete();
    }

    private function take(InventoryItem $row, int $quantity): void
    {
        $quantity >= $row->quantity ? $row->delete() : $row->forceFill(['quantity' => $row->quantity - $quantity])->save();
    }

    /**
     * Their home at `$from` is gone: what was stored there gets its deadline and they are told. Nothing stored, nothing said.
     */
    public function putAway(Character $character, Safehouse $from): void
    {
        $deadline = CarbonImmutable::now()->addHours((int) config('safehouse.storage_hours'));
        $n = $this->rows($character, $from)->whereNull('storage_expires_at')->update(['storage_expires_at' => $deadline]);

        if ($n > 0) {
            (new Notifier)->send($character, Category::City, 'safehouse_stored', ['place' => $from->title.' ['.($from->district->name ?? '').']'], ['collect' => $from->getKey()]);
        }
    }

    /** They hold this place again: whatever was waiting there is theirs to use. */
    public function reclaim(Character $character, Safehouse $home): void
    {
        $this->rows($character, $home)->whereNotNull('storage_expires_at')->update(['storage_expires_at' => null]);
    }

    /** Collect what is waiting at a lost home into the stash, as much as fits; the rest keeps its deadline. @return int rows moved */
    public function collect(Character $character, Safehouse $from): int
    {
        return DB::transaction(function () use ($character, $from): int {
            $locked = Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail();
            $moved = 0;

            foreach ($this->rows($locked, $from)->whereNotNull('storage_expires_at')->where('storage_expires_at', '>', now())->lockForUpdate()->get() as $stored) {
                if ($this->release($locked, $stored, $stored->quantity) === true) {
                    $moved++;
                }
            }

            return $moved;
        });
    }

    /** Rows still waiting at lost homes, grouped by the home, for the screen. @return \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, InventoryItem>> */
    public function waiting(Character $character): Collection
    {
        return InventoryItem::query()->with('item')->where('character_id', $character->getKey())
            ->whereNotNull('storage_expires_at')->where('storage_expires_at', '>', now())
            ->orderBy('storage_expires_at')->get()->groupBy('safehouse_id');
    }

    /** Delete what was not collected in time. @return int rows deleted */
    public function sweep(): int
    {
        return InventoryItem::query()->whereNotNull('safehouse_id')->where('storage_expires_at', '<=', now())->delete();
    }
}
