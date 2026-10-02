<?php

declare(strict_types=1);

namespace App\Market;

use App\Decks\Storage;
use App\Inventory\ItemGranter;
use App\Models\Character;
use App\Models\Item;
use App\Models\MarketPurchase;
use App\Models\MarketStock;
use App\Wallet\Reason;
use App\Wallet\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Buying something off the shelf.
 *
 * The only thing that spends `characters.creds`, the only writer of
 * `market_purchases`, and the only thing besides `MarketSeeder` that touches a
 * shelf. Money existed for four releases without anything to spend it on; this
 * is the other side of that.
 *
 * ## The price is the item's, never the shelf's
 *
 * `market_stock` carries no price on purpose, so a shelf row cannot drift out
 * of step with the catalogue it sells from. `items.base_cost` is what a thing
 * costs, and `market_purchases.unit_price` records what it cost THAT DAY —
 * which is the only copy that has to survive the catalogue being retuned.
 *
 * ## One transaction, and the shelf is locked inside it
 *
 * Two people reaching for the last one is the case this exists to get right.
 * The stock row is locked, the count is re-read inside the lock, and the money
 * and the goods move together or not at all.
 *
 * ## A purchase row is DEMAND, not a receipt
 *
 * `MarketSeeder` sums a window of them per item and stocks more of what is
 * selling, so writing one is half the reason this class exists. They are
 * immutable and they outlive the character who made them.
 */
final class Purchaser
{
    public function buy(Character $character, Item $item, int $quantity = 1): PurchaseResult
    {
        if ($quantity < 1) {
            return PurchaseResult::refused(PurchaseRefusal::NotEnoughLeft);
        }

        // Withdrawn from sale is the same answer as never stocked. The flag is
        // the seeder's question, and an item that stopped being on the market
        // should stop being buyable the moment it does.
        if (! $item->on_market) {
            return PurchaseResult::refused(PurchaseRefusal::NotStocked);
        }

        return DB::transaction(function () use ($character, $item, $quantity): PurchaseResult {
            $shelf = MarketStock::query()
                ->where('item_id', $item->getKey())
                ->lockForUpdate()
                ->first();

            $onHand = (int) ($shelf?->quantity ?? 0);

            if ($onHand < 1) {
                return PurchaseResult::refused(PurchaseRefusal::NotStocked);
            }

            if ($onHand < $quantity) {
                // Says so rather than quietly selling what is there: somebody
                // who asked for five and got two did not decide that.
                return PurchaseResult::refused(PurchaseRefusal::NotEnoughLeft);
            }

            $unit = (int) $item->base_cost;
            $total = $unit * $quantity;

            if ((int) $character->creds < $total) {
                return PurchaseResult::refused(PurchaseRefusal::CannotAfford);
            }

            if (! Storage::fits($character, Storage::sizeOf($item) * $quantity)) {
                return PurchaseResult::refused(PurchaseRefusal::NoStorage);
            }

            $shelf->forceFill(['quantity' => $onHand - $quantity])->save();

            Wallet::move($character, -$total, Reason::Market, $item->displayName().' × '.$quantity);

            ItemGranter::grant($character, $item, $quantity);

            MarketPurchase::query()->create([
                'character_id' => $character->getKey(),
                'item_id' => $item->getKey(),
                'quantity' => $quantity,
                // What it cost today, kept because the catalogue is allowed to
                // be retuned under a purchase that has already happened.
                'unit_price' => $unit,
                'purchased_at' => CarbonImmutable::now(),
            ]);

            return PurchaseResult::bought($item, $quantity, $total);
        });
    }
}
