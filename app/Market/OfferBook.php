<?php

declare(strict_types=1);

namespace App\Market;

use App\Decks\Storage;
use App\Inventory\ItemGranter;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\MarketOffer;
use App\Notifications\Category;
use App\Notifications\Notifier;
use App\Wallet\Reason;
use App\Wallet\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * Players selling to each other.
 *
 * THE ONLY WRITER OF `market_offers`, and the only thing in the game that
 * moves creds from one character to another — `Purchaser` spends them into the
 * shop, and nothing else touched the column at all. The shelf and this share a
 * screen and nothing else.
 *
 * ## A listing is a row held out of its owner's hands
 *
 * Listing does not copy, reserve or escrow anything: the offer row IS the
 * fact, and `InventoryItem::scopeLoose()` excludes anything an offer points
 * at. So a listed module cannot be fitted, a listed program cannot be run and
 * a listed file cannot be read in, without any of those three learning that a
 * market exists. Withdrawing is deleting the offer, and the row is simply back.
 *
 * ## The goods are the ROW, and that decides how they change hands
 *
 * An unstackable row is TRANSFERRED — same row, new owner — because what is
 * written on it is the thing being sold: a scan file's facts, a dump's
 * numbers, a deck's fitted modules. Granting a fresh copy of the catalogue
 * entry would hand over a blank one.
 *
 * A stackable row is granted instead, through the same merge-or-create every
 * other way of acquiring something uses, because two piles of one stackable
 * thing in one place are one pile — and because transferring the row would
 * collide on `stack_key` the moment the buyer already had some.
 *
 * ## A BUYER TAKES WHAT THEY WANT, and the rest stays listed
 *
 * The price is per unit and the listed row is the pile. Three of twenty is
 * three granted, seventeen left on the row, and the offer still standing at
 * the same figure; the last one taken deletes both. Asking for more than is
 * left is REFUSED rather than quietly reduced — the rule the shelf already
 * keeps, and for its reason: somebody who asked for five and could have had
 * two did not decide that.
 *
 * An unstackable row is a pile of one, so it needs no special case: one is
 * all anybody can ask for, and taking it empties the pile.
 *
 * ## The seller is TOLD, and the buyer is not
 *
 * A listing is left up and walked away from, so a sale is the definition of
 * something that happened while nobody was looking — creds appearing in an
 * account with nothing to explain them. The buyer pressed the button and has
 * a flash message; only the seller gets an inbox row.
 *
 * ## Nothing here writes `market_purchases`
 *
 * That table is DEMAND, read by `MarketSeeder` to decide what the SHOP restocks
 * more of. What one player sold another says nothing about what a shop should
 * order, and writing one would quietly teach the seeder to chase player trade.
 *
 * ponytail: the SELLER still lists a whole pile — there is no listing three of
 * the twenty you hold and keeping seventeen to hand. Add a split at listing
 * time (decrement the source, create the listed row, merge back on withdraw)
 * when somebody actually wants it; the buying side already divides.
 */
final readonly class OfferBook
{
    public function list(Character $seller, InventoryItem $row, int $price): OfferRefusal|MarketOffer
    {
        if ($row->character_id !== $seller->getKey()) {
            return OfferRefusal::NotYours;
        }

        // A deck being carried is not for sale. The alternative is asking
        // whether its owner is jacked in, which is a window rather than a rule
        // — and the answer changes while the listing sits there.
        if ($row->isEquipped()) {
            return OfferRefusal::Equipped;
        }

        if ($row->isOnPerson()) {
            return OfferRefusal::Worn;
        }

        if ($row->item?->use_skill_id !== null) {
            return OfferRefusal::NotForSale;
        }

        return DB::transaction(function () use ($seller, $row, $price): OfferRefusal|MarketOffer {
            // Re-asked inside the transaction and through the scope, so the
            // five ways of being spoken for are one question — and so that two
            // presses cannot both find the row to hand.
            $toHand = InventoryItem::query()
                ->whereKey($row->getKey())
                ->loose()
                ->lockForUpdate()
                ->exists();

            if (! $toHand) {
                return OfferRefusal::NotToHand;
            }

            return MarketOffer::query()->create([
                'seller_character_id' => $seller->getKey(),
                'inventory_item_id' => $row->getKey(),
                'unit_price' => max(0, $price),
            ]);
        });
    }

    /**
     * Take it back off the market.
     *
     * Nothing is confirmed and nothing is spent: the row was never anywhere
     * else, and deleting the offer is the whole of it.
     */
    public function withdraw(Character $seller, MarketOffer $offer): OfferRefusal|InventoryItem
    {
        if ($offer->seller_character_id !== $seller->getKey()) {
            return OfferRefusal::NotYours;
        }

        $row = $offer->row;

        if ($row === null) {
            return OfferRefusal::Gone;
        }

        $offer->delete();

        return $row;
    }

    /**
     * Buy what somebody else is holding.
     *
     * ONE TRANSACTION, AND THE OFFER AND ITS PILE ARE LOCKED INSIDE IT — the
     * case this exists to get right is two people reaching for the last three
     * of five, which on this side of the market is every sale: there is one
     * pile, and what is left of it is the goods.
     *
     * $quantity is how many of it they want. One, for everything that is not a
     * stack.
     */
    public function buy(Character $buyer, MarketOffer $offer, int $quantity = 1): OfferRefusal|InventoryItem
    {
        if ($offer->seller_character_id === $buyer->getKey()) {
            return OfferRefusal::YourOwn;
        }

        if ($quantity < 1) {
            return OfferRefusal::NotEnoughLeft;
        }

        return DB::transaction(function () use ($buyer, $offer, $quantity): OfferRefusal|InventoryItem {
            $live = MarketOffer::query()
                ->whereKey($offer->getKey())
                ->lockForUpdate()
                ->first();

            if ($live === null) {
                return OfferRefusal::Gone;
            }

            // Locked with the offer, because the pile is what is being
            // counted: two buyers reaching for the last three of five is the
            // case the whole transaction exists for.
            $row = $live->row()->with('item')->lockForUpdate()->first();
            $seller = $live->seller()->lockForUpdate()->first();

            if ($row === null || $row->item === null || $seller === null) {
                return OfferRefusal::Gone;
            }

            $onOffer = (int) $row->quantity;

            if ($onOffer < 1) {
                return OfferRefusal::Gone;
            }

            if ($onOffer < $quantity) {
                return OfferRefusal::NotEnoughLeft;
            }

            $price = $live->total($quantity);

            // Re-read inside the lock. The buyer's creds are the one number
            // here that something else in this session may have moved.
            $funds = (int) Character::query()->whereKey($buyer->getKey())->lockForUpdate()->value('creds');

            if ($funds < $price) {
                return OfferRefusal::CannotAfford;
            }

            // An unstackable row travels whole, and a file on it carries its
            // own size rather than its item's.
            $size = $row->item->stackable
                ? Storage::sizeOf($row->item) * $quantity
                : Storage::sizeOfRow($row);

            if (! Storage::fits($buyer, $size)) {
                return OfferRefusal::NoStorage;
            }

            $buyer->creds = $funds;
            Wallet::move($buyer, -$price, Reason::PlayerMarket, $row->item->displayName().' × '.$quantity);
            Wallet::move($seller, $price, Reason::PlayerMarket, $row->item->displayName().' × '.$quantity);

            // The listing outlives a partial sale: what is left is still for
            // sale, at the same figure, and `available()` reads it off the row.
            if ($quantity === $onOffer) {
                $live->delete();
            }

            // Read before the goods move: a pile bought out takes its row
            // with it, and a message naming what was sold has to outlive it.
            $sold = (string) $row->item->displayName();

            $goods = $this->handOver($buyer, $row, $quantity);

            // Inside the transaction, so a sale that rolls back cannot leave a
            // message behind saying it happened.
            (new Notifier)->send($seller, Category::Market, 'offer_sold', [
                'quantity' => (string) $quantity,
                'item' => $sold,
                'price' => number_format($price),
            ]);

            return $goods;
        });
    }

    /**
     * Move the goods, whichever of the two ways applies.
     *
     * The stackable branch loses the row and keeps the pile; the unstackable
     * branch keeps the row and everything written on it. See the class note —
     * this is the one decision that is not the same on both sides.
     */
    private function handOver(Character $buyer, InventoryItem $row, int $quantity): InventoryItem
    {
        if ($row->item->stackable) {
            $item = $row->item;
            $left = (int) $row->quantity - $quantity;

            // What is left stays where it is, still listed. Only an emptied
            // pile goes, and it takes nothing with it that anybody wanted.
            if ($left > 0) {
                $row->forceFill(['quantity' => $left])->save();
            } else {
                $row->delete();
            }

            ItemGranter::grant($buyer, $item, $quantity);

            return InventoryItem::query()
                ->where('character_id', $buyer->getKey())
                ->where('item_id', $item->getKey())
                ->loose()
                ->firstOrFail();
        }

        // Loose, always: which container it goes in is the buyer's choice and
        // ItemPlacer's question, the same call every other way of acquiring
        // something makes.
        $row->forceFill([
            'character_id' => $buyer->getKey(),
            'parent_id' => null,
        ])->save();

        return $row->fresh();
    }
}
