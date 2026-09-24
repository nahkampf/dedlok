<?php

declare(strict_types=1);

namespace Tests\Feature\Market;

use App\Market\OfferBook;
use App\Market\OfferRefusal;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\MarketOffer;
use App\Models\Notification;
use App\Notifications\Category;
use App\Support\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Players selling to each other.
 *
 * TWO INVARIANTS, and the tests exist for them: a listed row is out of its
 * owner's hands until the listing ends, and money and goods move together or
 * not at all. Everything else here is a refusal with a sentence attached.
 */
final class OfferBookTest extends TestCase
{
    use RefreshDatabase;

    private Character $seller;

    private Character $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seller = Character::factory()->create(['creds' => 100]);
        $this->buyer = Character::factory()->create(['creds' => 1000]);
    }

    public function test_a_listed_row_is_out_of_its_owners_hands(): void
    {
        $row = $this->owned($this->seller);

        $this->assertTrue($this->isLoose($row), 'it is to hand before it is listed');

        app(OfferBook::class)->list($this->seller, $row, 250);

        // The fifth way of being spoken for. Nothing that reads scopeLoose()
        // had to learn that a market exists.
        $this->assertFalse($this->isLoose($row));
        $this->assertTrue($row->fresh()->isListed());
    }

    public function test_withdrawing_puts_it_straight_back(): void
    {
        $row = $this->owned($this->seller);

        $offer = app(OfferBook::class)->list($this->seller, $row, 250);
        $this->assertInstanceOf(MarketOffer::class, $offer);

        $result = app(OfferBook::class)->withdraw($this->seller, $offer);

        $this->assertInstanceOf(InventoryItem::class, $result);
        $this->assertTrue($this->isLoose($row));
        $this->assertSame(0, MarketOffer::query()->count());
    }

    public function test_money_and_goods_move_together(): void
    {
        $row = $this->owned($this->seller);
        $offer = app(OfferBook::class)->list($this->seller, $row, 250);
        $this->assertInstanceOf(MarketOffer::class, $offer);

        $result = app(OfferBook::class)->buy($this->buyer, $offer);

        $this->assertInstanceOf(InventoryItem::class, $result);
        $this->assertSame($this->buyer->id, $result->character_id);
        $this->assertSame(750, (int) $this->buyer->fresh()->creds);
        $this->assertSame(350, (int) $this->seller->fresh()->creds);
        $this->assertSame(0, MarketOffer::query()->count(), 'the listing goes with the goods');
    }

    /**
     * THE SELLER IS TOLD, and only the seller.
     *
     * A listing is left up and walked away from, so the sale lands on nobody's
     * screen — which is the inbox's whole test. The buyer pressed the button
     * and already has a flash message.
     */
    public function test_a_sale_reaches_the_sellers_inbox(): void
    {
        $item = Item::factory()->create(['stackable' => true, 'name' => 'Ice Pick']);

        $row = InventoryItem::query()->create([
            'character_id' => $this->seller->id,
            'item_id' => $item->id,
            'quantity' => 5,
        ]);

        $offer = app(OfferBook::class)->list($this->seller, $row, 40);
        $this->assertInstanceOf(MarketOffer::class, $offer);

        app(OfferBook::class)->buy($this->buyer, $offer, 2);

        $message = Notification::query()->sole();

        $this->assertSame($this->seller->user_id, $message->user_id);
        $this->assertSame(Category::Market, $message->category);
        $this->assertSame(
            Text::get('notifications.messages.offer_sold', [
                'quantity' => '2',
                'item' => 'Ice Pick',
                'price' => '80',
            ]),
            $message->body,
        );
    }

    /** A refused sale is not a sale, and says nothing to anybody. */
    public function test_a_refused_sale_writes_no_message(): void
    {
        $row = $this->owned($this->seller);
        $offer = app(OfferBook::class)->list($this->seller, $row, 5000);
        $this->assertInstanceOf(MarketOffer::class, $offer);

        app(OfferBook::class)->buy($this->buyer, $offer);

        $this->assertSame(0, Notification::query()->count());
    }

    /**
     * An unstackable row is TRANSFERRED, not re-granted.
     *
     * What is written on the row is the thing being sold — a scan file's
     * facts, a dump's numbers — so handing over a fresh copy of the catalogue
     * entry would hand over a blank one.
     */
    public function test_an_unstackable_row_keeps_what_is_written_on_it(): void
    {
        $row = $this->owned($this->seller, stackable: false);
        $row->forceFill(['scan_file' => ['address' => 'A1B2C3D4', 'grade' => 7]])->save();

        $offer = app(OfferBook::class)->list($this->seller, $row, 10);
        $this->assertInstanceOf(MarketOffer::class, $offer);

        $result = app(OfferBook::class)->buy($this->buyer, $offer);

        $this->assertInstanceOf(InventoryItem::class, $result);
        $this->assertSame($row->id, $result->id, 'the same row changed hands');
        $this->assertSame('A1B2C3D4', $result->scan_file['address']);
    }

    /**
     * A BUYER TAKES WHAT THEY WANT, and the rest stays listed.
     *
     * The price is per unit and the listed row is the pile, so three of twenty
     * is three granted, seventeen left, and the offer still standing.
     */
    public function test_part_of_a_stack_can_be_bought(): void
    {
        $item = Item::factory()->create(['stackable' => true]);

        $row = InventoryItem::query()->create([
            'character_id' => $this->seller->id,
            'item_id' => $item->id,
            'quantity' => 20,
        ]);

        $offer = app(OfferBook::class)->list($this->seller, $row, 10);
        $this->assertInstanceOf(MarketOffer::class, $offer);

        $result = app(OfferBook::class)->buy($this->buyer, $offer, 3);

        $this->assertInstanceOf(InventoryItem::class, $result);
        $this->assertSame(3, (int) $result->quantity, 'they got three');
        $this->assertSame(17, (int) $row->fresh()->quantity, 'seventeen are still on offer');
        $this->assertSame(1, MarketOffer::query()->count(), 'and still listed');

        $this->assertSame(970, (int) $this->buyer->fresh()->creds);
        $this->assertSame(130, (int) $this->seller->fresh()->creds);
    }

    /** Taking the last of a pile takes the listing with it. */
    public function test_buying_the_whole_pile_ends_the_listing(): void
    {
        $item = Item::factory()->create(['stackable' => true]);

        $row = InventoryItem::query()->create([
            'character_id' => $this->seller->id,
            'item_id' => $item->id,
            'quantity' => 4,
        ]);

        $offer = app(OfferBook::class)->list($this->seller, $row, 10);
        $this->assertInstanceOf(MarketOffer::class, $offer);

        app(OfferBook::class)->buy($this->buyer, $offer, 4);

        $this->assertNull(InventoryItem::query()->find($row->id));
        $this->assertSame(0, MarketOffer::query()->count());
        $this->assertSame(4, (int) InventoryItem::query()
            ->where('character_id', $this->buyer->id)->sole()->quantity);
    }

    /** Asking for more than is left is refused, never quietly reduced. */
    public function test_asking_for_more_than_is_on_offer(): void
    {
        $item = Item::factory()->create(['stackable' => true]);

        $row = InventoryItem::query()->create([
            'character_id' => $this->seller->id,
            'item_id' => $item->id,
            'quantity' => 2,
        ]);

        $offer = app(OfferBook::class)->list($this->seller, $row, 10);
        $this->assertInstanceOf(MarketOffer::class, $offer);

        $this->assertSame(
            OfferRefusal::NotEnoughLeft,
            app(OfferBook::class)->buy($this->buyer, $offer, 5),
        );

        $this->assertSame(1000, (int) $this->buyer->fresh()->creds);
        $this->assertSame(2, (int) $row->fresh()->quantity);
    }

    /** Two piles of one stackable thing in one place are one pile, however each arrived. */
    public function test_a_stackable_row_merges_into_what_the_buyer_already_has(): void
    {
        $item = Item::factory()->create(['stackable' => true]);

        $theirs = InventoryItem::query()->create([
            'character_id' => $this->buyer->id,
            'item_id' => $item->id,
            'quantity' => 2,
        ]);

        $row = InventoryItem::query()->create([
            'character_id' => $this->seller->id,
            'item_id' => $item->id,
            'quantity' => 3,
        ]);

        $offer = app(OfferBook::class)->list($this->seller, $row, 10);
        $this->assertInstanceOf(MarketOffer::class, $offer);

        app(OfferBook::class)->buy($this->buyer, $offer, 3);

        $this->assertSame(5, (int) $theirs->fresh()->quantity);
        $this->assertNull(InventoryItem::query()->find($row->id));
        $this->assertSame(
            1,
            InventoryItem::query()->where('character_id', $this->buyer->id)->count(),
        );
    }

    public function test_a_row_that_is_spoken_for_cannot_be_listed(): void
    {
        $row = $this->owned($this->seller);
        $container = $this->owned($this->seller);

        $row->forceFill(['parent_id' => $container->id])->save();

        $this->assertSame(
            OfferRefusal::NotToHand,
            app(OfferBook::class)->list($this->seller, $row->fresh(), 250),
        );
    }

    public function test_listing_the_same_row_twice_is_refused(): void
    {
        $row = $this->owned($this->seller);

        app(OfferBook::class)->list($this->seller, $row, 250);

        $this->assertSame(
            OfferRefusal::NotToHand,
            app(OfferBook::class)->list($this->seller, $row->fresh(), 400),
        );

        $this->assertSame(1, MarketOffer::query()->count());
    }

    public function test_somebody_elses_row_is_not_yours_to_sell(): void
    {
        $row = $this->owned($this->buyer);

        $this->assertSame(
            OfferRefusal::NotYours,
            app(OfferBook::class)->list($this->seller, $row, 250),
        );
    }

    public function test_you_cannot_buy_your_own_listing(): void
    {
        $row = $this->owned($this->seller);
        $offer = app(OfferBook::class)->list($this->seller, $row, 10);
        $this->assertInstanceOf(MarketOffer::class, $offer);

        $this->assertSame(
            OfferRefusal::YourOwn,
            app(OfferBook::class)->buy($this->seller, $offer),
        );
    }

    public function test_an_unaffordable_offer_moves_nothing(): void
    {
        $row = $this->owned($this->seller);
        $offer = app(OfferBook::class)->list($this->seller, $row, 5000);
        $this->assertInstanceOf(MarketOffer::class, $offer);

        $this->assertSame(
            OfferRefusal::CannotAfford,
            app(OfferBook::class)->buy($this->buyer, $offer),
        );

        $this->assertSame(1000, (int) $this->buyer->fresh()->creds);
        $this->assertSame(100, (int) $this->seller->fresh()->creds);
        $this->assertSame($this->seller->id, $row->fresh()->character_id);
        $this->assertSame(1, MarketOffer::query()->count(), 'a refused sale leaves the listing up');
    }

    /** Somebody got there first. The offer is gone, and so is everything it described. */
    public function test_buying_one_that_has_already_gone(): void
    {
        $row = $this->owned($this->seller);
        $offer = app(OfferBook::class)->list($this->seller, $row, 10);
        $this->assertInstanceOf(MarketOffer::class, $offer);

        MarketOffer::query()->whereKey($offer->getKey())->delete();

        $this->assertSame(
            OfferRefusal::Gone,
            app(OfferBook::class)->buy($this->buyer, $offer),
        );

        $this->assertSame(1000, (int) $this->buyer->fresh()->creds);
    }

    private function isLoose(InventoryItem $row): bool
    {
        return InventoryItem::query()->whereKey($row->getKey())->loose()->exists();
    }

    private function owned(Character $character, bool $stackable = false): InventoryItem
    {
        return InventoryItem::query()->create([
            'character_id' => $character->id,
            'item_id' => Item::factory()->create(['stackable' => $stackable])->id,
            'quantity' => 1,
        ]);
    }
}
