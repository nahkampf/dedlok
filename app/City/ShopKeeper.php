<?php

declare(strict_types=1);

namespace App\City;

use App\Decks\Storage;
use App\Inventory\ItemGranter;
use App\Models\Character;
use App\Models\Location;
use App\Models\ShopItem;
use App\Support\Text;
use App\Wallet\Reason;
use App\Wallet\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * The only door between a City shop and a stash.
 *
 * It writes NEITHER of the market's tables: `market_purchases` is the seeder's
 * demand signal and `market_stock` is its shelf, and a shop is neither. It also
 * ignores `items.on_market`, since selling what the market does not is the
 * point of a shop. Stock is unlimited, so there is nothing to lock but the
 * wallet.
 *
 * Money moves under a lock on the character row, with the funds re-read inside
 * it; `Wallet::move()` does not check. Data is a door `Storage` guards like
 * every other.
 */
final class ShopKeeper
{
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

            $listing = ShopItem::query()->with('item')->where('location_id', $place->getKey())->where('item_id', $itemId)->first();

            if ($listing === null || $listing->item === null) {
                return CityRefusal::NotSold;
            }

            $total = $listing->unitPrice() * $quantity;

            if ((int) $locked->creds < $total) {
                return CityRefusal::CannotAfford;
            }

            if (! Storage::fits($locked, Storage::sizeOf($listing->item) * $quantity)) {
                return CityRefusal::NoStorage;
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
}
