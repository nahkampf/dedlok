<?php

declare(strict_types=1);

namespace Tests\Feature\Market;

use App\Market\OfferBook;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\MarketOffer;
use App\Support\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two doors a player actually presses.
 *
 * Listing is reached from the item's CARD and buying from the offer, so the
 * routes are on two different screens — which is the thing worth a test here.
 * What the writer refuses is `OfferBookTest`'s subject; this is the wiring.
 */
final class OfferScreenTest extends TestCase
{
    use RefreshDatabase;

    private Character $seller;

    private Character $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seller = $this->aRunner(creds: 0);
        $this->buyer = $this->aRunner(creds: 1000);
    }

    public function test_the_screen_lists_what_runners_are_selling(): void
    {
        $row = $this->listed(300);

        $this->actingAs($this->buyer->user)
            ->get(route('market.index'))
            ->assertOk()
            ->assertSeeText($row->item->displayName())
            ->assertSeeText(Text::get('market.offers.seller', ['name' => $this->seller->handle]));
    }

    public function test_an_empty_board_says_so(): void
    {
        $this->actingAs($this->buyer->user)
            ->get(route('market.index'))
            ->assertOk()
            ->assertSeeText(Text::get('market.offers.empty'));
    }

    public function test_a_card_offers_to_sell_the_row_it_is_showing(): void
    {
        $row = $this->owned($this->seller);

        $this->actingAs($this->seller->user)
            ->get(route('inventory.index'))
            ->assertOk()
            ->assertSee('action="'.route('market.list', $row).'"', false);
    }

    public function test_listing_and_then_buying_through_the_routes(): void
    {
        $row = $this->owned($this->seller);

        $this->actingAs($this->seller->user)
            ->post(route('market.list', $row), ['price' => 300])
            ->assertRedirect();

        $offer = MarketOffer::query()->sole();

        $this->actingAs($this->buyer->user)
            ->post(route('market.take', $offer))
            ->assertRedirect();

        $this->assertSame($this->buyer->id, $row->fresh()->character_id);
        $this->assertSame(700, (int) $this->buyer->fresh()->creds);
        $this->assertSame(300, (int) $this->seller->fresh()->creds);
    }

    /** The quantity field, and what the route does with it. */
    public function test_buying_part_of_a_stack_through_the_route(): void
    {
        $row = InventoryItem::query()->create([
            'character_id' => $this->seller->id,
            'item_id' => Item::factory()->create(['stackable' => true])->id,
            'quantity' => 10,
        ]);

        app(OfferBook::class)->list($this->seller, $row, 50);
        $offer = MarketOffer::query()->sole();

        $this->actingAs($this->buyer->user)
            ->get(route('market.index'))
            ->assertOk()
            ->assertSee('name="quantity"', false)
            ->assertSeeText(Text::plural('market.offers.available', 10));

        $this->actingAs($this->buyer->user)
            ->post(route('market.take', $offer), ['quantity' => 4])
            ->assertRedirect();

        $this->assertSame(6, (int) $row->fresh()->quantity);
        $this->assertSame(800, (int) $this->buyer->fresh()->creds);
        $this->assertSame(200, (int) $this->seller->fresh()->creds);
        $this->assertSame(1, MarketOffer::query()->count(), 'what is left is still for sale');
    }

    /** One of a kind: nothing to choose, so nothing is asked. */
    public function test_a_single_row_offers_no_quantity_field(): void
    {
        $this->listed(300);

        $this->actingAs($this->buyer->user)
            ->get(route('market.index'))
            ->assertOk()
            ->assertDontSee('name="quantity"', false);
    }

    public function test_withdrawing_through_the_route(): void
    {
        $row = $this->listed(300);
        $offer = MarketOffer::query()->sole();

        $this->actingAs($this->seller->user)
            ->delete(route('market.withdraw', $offer))
            ->assertRedirect();

        $this->assertSame(0, MarketOffer::query()->count());
        $this->assertTrue(
            InventoryItem::query()->whereKey($row->getKey())->loose()->exists(),
        );
    }

    /** Somebody else's listing is not a refusal to explain; it is not theirs to touch. */
    public function test_withdrawing_somebody_elses_listing_is_a_404(): void
    {
        $this->listed(300);

        $this->actingAs($this->buyer->user)
            ->delete(route('market.withdraw', MarketOffer::query()->sole()))
            ->assertNotFound();

        $this->assertSame(1, MarketOffer::query()->count());
    }

    public function test_listing_somebody_elses_row_is_a_404(): void
    {
        $row = $this->owned($this->seller);

        $this->actingAs($this->buyer->user)
            ->post(route('market.list', $row), ['price' => 10])
            ->assertNotFound();

        $this->assertSame(0, MarketOffer::query()->count());
    }

    private function listed(int $price): InventoryItem
    {
        $row = $this->owned($this->seller);

        app(OfferBook::class)->list($this->seller, $row, $price);

        return $row;
    }

    private function owned(Character $character): InventoryItem
    {
        return InventoryItem::query()->create([
            'character_id' => $character->id,
            'item_id' => Item::factory()->create(['stackable' => false])->id,
            'quantity' => 1,
        ]);
    }

    private function aRunner(int $creds): Character
    {
        return Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 10),
            ['archetype' => 3, 'creds' => $creds],
        ));
    }
}
