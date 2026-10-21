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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasRoomForData;
use Tests\TestCase;

/** A contract is a market offer with one named buyer: only they (and the seller) see it, and only they can buy it. */
final class ContractTest extends TestCase
{
    use HasRoomForData;
    use RefreshDatabase;

    private Character $seller;

    private Character $recipient;

    private Character $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seller = Character::factory()->create();
        $this->recipient = Character::factory()->create(['creds' => 1000]);
        $this->outsider = Character::factory()->create(['creds' => 1000]);
        $this->giveRoomForData($this->recipient);
        $this->giveRoomForData($this->outsider);
    }

    private function contract(): MarketOffer
    {
        $row = InventoryItem::query()->create(['character_id' => $this->seller->id, 'item_id' => Item::factory()->create(['name' => 'Secret Thing'])->id, 'quantity' => 1]);

        return app(OfferBook::class)->list($this->seller, $row, 100, null, $this->recipient);
    }

    public function test_only_the_recipient_can_buy_it(): void
    {
        $offer = $this->contract();

        $this->assertSame(OfferRefusal::Gone, app(OfferBook::class)->buy($this->outsider, $offer));
        $this->assertSame(1000, (int) $this->outsider->fresh()->creds);
        $this->assertInstanceOf(InventoryItem::class, app(OfferBook::class)->buy($this->recipient, $offer));
        $this->assertSame(900, (int) $this->recipient->fresh()->creds);
    }

    public function test_it_is_visible_to_the_recipient_and_the_seller_alone(): void
    {
        $offer = $this->contract();
        $public = InventoryItem::query()->create(['character_id' => $this->seller->id, 'item_id' => Item::factory()->create()->id, 'quantity' => 1]);
        app(OfferBook::class)->list($this->seller, $public, 5);

        $seen = fn (Character $who): array => MarketOffer::query()->visibleTo($who)->pluck('id')->all();

        $this->assertContains($offer->id, $seen($this->recipient));
        $this->assertContains($offer->id, $seen($this->seller));
        $this->assertNotContains($offer->id, $seen($this->outsider));
        $this->assertCount(1, $seen($this->outsider), 'the ordinary listing is still public');
    }

    public function test_the_market_and_the_listing_link_hide_it_from_everybody_else(): void
    {
        $offer = $this->contract();

        $this->actingAs($this->outsider->user)->get(route('market.index'))->assertOk()->assertDontSee('Secret Thing');
        $this->actingAs($this->outsider->user)->get(route('market.listing', $offer))->assertOk()->assertDontSee('Secret Thing');
        $this->actingAs($this->recipient->user)->get(route('market.index'))->assertOk()->assertSee('Secret Thing');
    }

    public function test_the_form_makes_one_by_handle_and_refuses_a_name_nobody_has(): void
    {
        $row = InventoryItem::query()->create(['character_id' => $this->seller->id, 'item_id' => Item::factory()->create()->id, 'quantity' => 1]);

        $this->actingAs($this->seller->user)->post(route('market.list', $row), ['price' => 10, 'recipient' => 'nobody-by-this-name'])->assertSessionHas('error');
        $this->assertSame(0, MarketOffer::query()->count());

        $this->actingAs($this->seller->user)->post(route('market.list', $row), ['price' => 10, 'recipient' => $this->recipient->handle])->assertSessionHas('error');
        $this->assertSame(0, MarketOffer::query()->count(), 'a name never turns into a public listing, nor a contract without one');
        $this->actingAs($this->seller->user)->post(route('market.list', $row), ['price' => 10, 'contract' => 1])->assertSessionHas('error');
        $this->assertSame(0, MarketOffer::query()->count());

        $this->actingAs($this->seller->user)->post(route('market.list', $row), ['price' => 10, 'contract' => 1, 'recipient' => $this->recipient->handle])->assertSessionHas('status');
        $this->assertSame($this->recipient->id, MarketOffer::query()->sole()->recipient_character_id);
    }

    public function test_the_recipient_is_told(): void
    {
        $this->contract();

        $this->assertSame(1, Notification::query()->where('user_id', $this->recipient->user_id)->count());
    }
}
