<?php

declare(strict_types=1);

namespace App\Trade;

use App\City\CityRefusal;
use App\Models\Character;
use App\Models\EntityBuy;
use App\Models\InventoryItem;
use App\Models\LocationBuy;
use App\Wallet\Reason;
use App\Wallet\Wallet;
use Carbon\CarbonImmutable;

/**
 * Selling to a buyer: the rules a location and an exchange desk share.
 *
 * The caller owns the transaction and has locked the character and checked that they are THERE (`Presence` for a
 * place, the desk for an exchange). This owns the rest: what a listing will take, what it pays, how many more it
 * will take this window, and the row leaving the stash. The listing is locked here, so two sellers cannot both
 * take the last of a limit.
 *
 * Only what is in the character's hands: not the deck being carried, not what is worn or wielded, and nothing that
 * holds or is fitted to other things. What is sold is gone.
 */
final class Buying
{
    /** Not the deck being carried, armour being worn, a container with things in it, or a deck with modules fitted. */
    public static function mayLeave(Character $character, InventoryItem $row): bool
    {
        return $character->equipped_deck_id !== $row->id
            && ! $row->isOnPerson()
            && ! $row->children()->exists()
            && ! $row->fittings()->exists();
    }

    /**
     * What the character holds that these listings would take, with the price of each and the room left.
     *
     * @param  iterable<LocationBuy|EntityBuy>  $listings
     * @return list<array{row: InventoryItem, unit: int, left: int|null}>
     */
    public static function sellable(Character $character, iterable $listings): array
    {
        $byItem = collect($listings)->keyBy('item_id');

        if ($byItem->isEmpty()) {
            return [];
        }

        $now = CarbonImmutable::now();
        $out = [];

        foreach (InventoryItem::query()->with('item')->where('character_id', $character->getKey())->loose()->whereIn('item_id', $byItem->keys())->orderBy('id')->get() as $row) {
            if (! self::mayLeave($character, $row)) {
                continue;
            }

            $listing = clone $byItem[$row->item_id];
            $listing->settleWindow($now);

            $out[] = ['row' => $row, 'unit' => $listing->unitPriceFor($row), 'left' => $listing->remaining()];
        }

        return $out;
    }

    /**
     * Sell `$quantity` of one row to a listing.
     *
     * @param  LocationBuy|EntityBuy  $listing  an unlocked listing; it is re-read under a lock here
     * @return int|CityRefusal creds received
     */
    public static function sell(Character $locked, LocationBuy|EntityBuy $listing, int $rowId, int $quantity, string $note): int|CityRefusal
    {
        $listing = $listing::query()->with('item')->whereKey($listing->getKey())->lockForUpdate()->first();
        $row = InventoryItem::query()->with('item')->where('character_id', $locked->getKey())->loose()->whereKey($rowId)->lockForUpdate()->first();

        if ($listing === null || $row === null || $row->item_id !== $listing->item_id || ! self::mayLeave($locked, $row) || $quantity < 1 || $quantity > (int) $row->quantity) {
            return CityRefusal::CannotSell;
        }

        $listing->settleWindow(CarbonImmutable::now());

        // Refused, not quietly reduced: somebody who offered five and had two taken did not decide that.
        if ($listing->remaining() !== null && $quantity > $listing->remaining()) {
            return CityRefusal::WontTakeMore;
        }

        $total = $listing->unitPriceFor($row) * $quantity;

        $listing->forceFill(['bought' => (int) $listing->bought + $quantity])->save();

        if ($quantity === (int) $row->quantity) {
            $row->delete();
        } else {
            $row->forceFill(['quantity' => $row->quantity - $quantity])->save();
        }

        Wallet::move($locked, $total, Reason::Sale, $note);

        return $total;
    }
}
