<?php

declare(strict_types=1);

namespace App\Standing;

use App\Inventory\ItemGranter;
use App\Models\Character;
use App\Models\Entity;
use App\Models\EntityOffer;
use App\Models\Exchange;
use App\Models\Item;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Spending loyalty at an entity's own shelf.
 *
 * THE ONLY THING THAT SPENDS LOYALTY. Missions are the only thing that earns
 * it, `StandingLedger` is the only thing that writes either book, and this
 * sits between them — which is why it is here beside them rather than in
 * `App\Market`, where the creds economy lives. The two currencies never meet:
 * nothing converts loyalty to creds or back.
 *
 * ## Two gates, asked in order
 *
 * The DESK decides whether it deals with somebody at all — `exchanges
 * .min_standing`, the same gate their work reads, so an agent who will not
 * employ you does not sell to you either. Then the ROW may ask for more, which
 * is almost always nothing.
 *
 * ## One transaction, both books read inside it
 *
 * Loyalty is not creds: it never decays, so nothing else moves it while
 * somebody is standing here. The lock is still taken, because "what they can
 * afford" and "what they are charged" must be the same read — the case the
 * offer book already exists to get right.
 *
 * ## Spending EARNS a little standing
 *
 * `StandingReason::StoreSpend` — "money spent at their store, which is its own
 * small courtesy". Proportional to what was spent, at a ratio that lives with
 * the store rather than in the reasons table, because it is a property of the
 * shop and not of the fact.
 */
final readonly class LoyaltyExchange
{
    public function buy(Character $character, Exchange $desk, EntityOffer $offer): LoyaltyRefusal|Item
    {
        $entity = $desk->entity();

        if ($entity === null || $offer->entity_id !== $entity->getKey() || $offer->grade !== (int) $desk->grade) {
            // Somebody else's shelf, or another grade's. Not a refusal worth
            // explaining: as far as this desk is concerned the row is not here.
            return LoyaltyRefusal::Gone;
        }

        return DB::transaction(function () use ($character, $desk, $offer, $entity): LoyaltyRefusal|Item {
            $live = EntityOffer::query()->whereKey($offer->getKey())->with('item')->lockForUpdate()->first();

            if ($live === null || $live->item === null) {
                return LoyaltyRefusal::Gone;
            }

            $ledger = new StandingLedger;
            $books = $ledger->booksFor($character, $entity);

            $standing = (float) ($books->standing ?? 0.0);
            $loyalty = (int) ($books->loyalty ?? 0);

            // The desk first, then the row. Two gates, and the first is the
            // one their missions already read.
            if (! $desk->willDealWith($standing)) {
                return LoyaltyRefusal::NotWelcome;
            }

            if (! $live->willPartWithIt($standing)) {
                return LoyaltyRefusal::NotForYou;
            }

            $price = (int) $live->loyalty_price;

            if ($loyalty < $price) {
                return LoyaltyRefusal::CannotAfford;
            }

            // Loyalty out, as a ledger entry like everything else that moves a
            // book — so `standing:verify` still finds the books equal to the
            // sum of their entries afterwards.
            $ledger->record($character, $entity, StandingReason::StoreSpend, -$price, LedgerKind::Loyalty);

            $courtesy = $this->courtesyFor($price);

            if ($courtesy > 0.0) {
                $ledger->record($character, $entity, StandingReason::StoreSpend, $courtesy);
            }

            // The same merge-or-create every other way of acquiring something
            // uses. It lands LOOSE; which container it goes in is the player's
            // choice and ItemPlacer's question.
            ItemGranter::grant($character, $live->item);

            return $live->item;
        });
    }

    /**
     * The standing a spend is worth, which is deliberately small.
     *
     * Buying something is a courtesy rather than a service: it should nudge an
     * agent's opinion without being a way to BUY standing, so the ratio is
     * per-loyalty-point and rounds to the column's three decimals. At the
     * default it takes a thousand loyalty to move standing by a point, and a
     * thousand loyalty is a great many missions.
     */
    private function courtesyFor(int $price): float
    {
        $ratio = (float) config('standing.store.standing_per_loyalty', 0.0);

        return round($price * $ratio, 3);
    }

    /**
     * What this desk is holding, for the screen.
     *
     * @return Collection<int, EntityOffer>
     */
    public function shelf(Entity $entity, int $grade): Collection
    {
        return EntityOffer::query()
            ->onShelf($entity->getKey(), $grade)
            ->with('item')
            ->get();
    }
}
