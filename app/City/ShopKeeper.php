<?php

declare(strict_types=1);

namespace App\City;

use App\Decks\Storage;
use App\Inventory\ItemGranter;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Location;
use App\Models\LocationBuy;
use App\Models\ShopItem;
use App\Support\Text;
use App\Wallet\Reason;
use App\Wallet\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only door between a City shop and a stash.
 *
 * It writes NEITHER of the market's tables: `market_purchases` is the seeder's
 * demand signal and `market_stock` is its shelf, and a shop is neither. It also
 * ignores `items.on_market`, since selling what the market does not is the
 * point of a shop.
 *
 * A listing's stock restocks LAZILY: each whole interval since its last
 * boundary adds the restock amount up to the maximum, settled under a lock by
 * whoever looks next (`ShopItem::restock()`). No maximum means unlimited. This
 * is the only writer of `shop_items.stock` besides the editor, which starts a
 * new listing full.
 *
 * Money moves under a lock on the character row, with the funds re-read inside
 * it; `Wallet::move()` does not check. Data is a door `Storage` guards like
 * every other. Selling is the other direction: only what a place's buy list
 * names, only what is in the character's hands, and what is sold is gone.
 */
final class ShopKeeper
{
    /**
     * The wares with their stock brought up to date, for a screen to show.
     *
     * @return Collection<int, ShopItem>
     */
    public function wares(Location $place): Collection
    {
        return DB::transaction(function () use ($place): Collection {
            $rows = ShopItem::query()->with('item')->where('location_id', $place->getKey())->orderBy('id')->lockForUpdate()->get();

            foreach ($rows as $row) {
                $row->restock(CarbonImmutable::now());
                $row->isDirty() && $row->save();
            }

            return $rows;
        });
    }

    /** @return int|CityRefusal how many were bought */
    public function buy(Character $character, Location $place, int $itemId, int $quantity): int|CityRefusal
    {
        if ($quantity < 1) {
            return CityRefusal::NotSold;
        }

        return DB::transaction(function () use ($character, $place, $itemId, $quantity): int|CityRefusal {
            $locked = Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail();

            if ($refusal = Presence::refusal($locked, $place)) {
                return $refusal;
            }

            $listing = ShopItem::query()->with('item')->where('location_id', $place->getKey())->where('item_id', $itemId)->lockForUpdate()->first();

            if ($listing === null || $listing->item === null) {
                return CityRefusal::NotSold;
            }

            $listing->restock(CarbonImmutable::now());
            $listing->isDirty() && $listing->save();

            if (! $listing->isUnlimited() && $listing->stock < $quantity) {
                return CityRefusal::SoldOut;
            }

            $total = $listing->unitPrice() * $quantity;

            if ((int) $locked->creds < $total) {
                return CityRefusal::CannotAfford;
            }

            if (! Storage::fits($locked, Storage::sizeOf($listing->item) * $quantity)) {
                return CityRefusal::NoStorage;
            }

            if (! $listing->isUnlimited()) {
                $listing->forceFill(['stock' => $listing->stock - $quantity])->save();
            }

            Wallet::move($locked, -$total, Reason::Shop, Text::get('city.shop.note', [
                'item' => $listing->item->displayName(),
                'quantity' => $quantity,
                'place' => $place->name,
            ]));
            ItemGranter::grant($locked, $listing->item, $quantity);

            $character->refresh();

            return $quantity;
        });
    }

    /**
     * What this character could sell here: the loose rows the place's buy list names.
     *
     * @return list<array{row: InventoryItem, unit: int}>
     */
    public function sellable(Character $character, Location $place): array
    {
        $buys = LocationBuy::query()->with('item')->where('location_id', $place->getKey())->get()->keyBy('item_id');

        if ($buys->isEmpty()) {
            return [];
        }

        $out = [];

        foreach (InventoryItem::query()->with('item')->where('character_id', $character->getKey())->loose()->whereIn('item_id', $buys->keys())->orderBy('id')->get() as $row) {
            if ($this->mayLeave($character, $row)) {
                $out[] = ['row' => $row, 'unit' => $buys[$row->item_id]->unitPrice()];
            }
        }

        return $out;
    }

    /** @return int|CityRefusal creds received */
    public function sell(Character $character, Location $place, int $rowId, int $quantity): int|CityRefusal
    {
        if ($quantity < 1) {
            return CityRefusal::CannotSell;
        }

        return DB::transaction(function () use ($character, $place, $rowId, $quantity): int|CityRefusal {
            $locked = Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail();

            if ($refusal = Presence::refusal($locked, $place)) {
                return $refusal;
            }

            $row = InventoryItem::query()->with('item')->where('character_id', $locked->getKey())->loose()->whereKey($rowId)->lockForUpdate()->first();
            $buy = $row === null ? null : LocationBuy::query()->with('item')->where('location_id', $place->getKey())->where('item_id', $row->item_id)->first();

            if ($row === null || $buy === null || ! $this->mayLeave($locked, $row) || $quantity > (int) $row->quantity) {
                return CityRefusal::CannotSell;
            }

            $total = $buy->unitPrice() * $quantity;

            if ($quantity === (int) $row->quantity) {
                $row->delete();
            } else {
                $row->forceFill(['quantity' => $row->quantity - $quantity])->save();
            }

            Wallet::move($locked, $total, Reason::Sale, Text::get('city.shop.sale_note', [
                'item' => $buy->item->displayName(),
                'quantity' => $quantity,
                'place' => $place->name,
            ]));

            $character->refresh();

            return $total;
        });
    }

    /** Not the deck being carried, not armour being worn, and nothing that holds or carries other things. */
    private function mayLeave(Character $character, InventoryItem $row): bool
    {
        return $character->equipped_deck_id !== $row->id
            && ! $row->isOnPerson()
            && ! $row->children()->exists()
            && ! $row->fittings()->exists();
    }
}
