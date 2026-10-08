<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Admin\DeletionImpact;
use App\City\CityRefusal;
use App\City\FeatureEditor;
use App\City\ShopKeeper;
use App\Models\Character;
use App\Models\District;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Location;
use App\Models\LocationBuy;
use App\Models\ShopItem;
use App\Models\User;
use App\Models\WalletEntry;
use App\Wallet\Reason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Shop stock and its lazy restock, and selling to a place. */
final class CityShopStockTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    private Location $place;

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['archetype' => 3, 'creds' => 1000],
        ));

        $this->place = Location::create([
            'district_id' => District::startId(), 'slug' => 'stall', 'name' => 'Stall',
            'map_x' => 50, 'map_y' => 50, 'discovery' => 'open',
        ]);
    }

    private function listing(Item $item, array $extra = []): ShopItem
    {
        return ShopItem::create(array_merge([
            'location_id' => $this->place->id, 'item_id' => $item->id, 'price' => 10,
            'max_stock' => 5, 'stock' => 5, 'restock_amount' => 2, 'restock_hours' => 2, 'restocked_at' => now(),
        ], $extra));
    }

    private function buy(Item $item, int $quantity = 1): int|CityRefusal
    {
        return app(ShopKeeper::class)->buy($this->character->fresh(), $this->place, $item->id, $quantity);
    }

    public function test_a_sale_takes_from_the_shelf_and_an_empty_shelf_is_sold_out(): void
    {
        $item = Item::factory()->physical()->create();
        $row = $this->listing($item);

        $this->assertSame(3, $this->buy($item, 3));
        $this->assertSame(2, $row->fresh()->stock);

        $this->assertSame(CityRefusal::SoldOut, $this->buy($item, 3));
        $this->assertSame(970, $this->character->fresh()->creds, 'only the first sale was charged');
    }

    public function test_it_restocks_by_whole_intervals_up_to_the_maximum(): void
    {
        $item = Item::factory()->physical()->create();
        $row = $this->listing($item);
        $this->buy($item, 5);
        $this->assertSame(0, $row->fresh()->stock);

        $this->travel(3)->hours();
        $this->assertSame(2, app(ShopKeeper::class)->wares($this->place)->first()->stock, 'three hours of a two-hour interval is one restock: +2');

        $this->travel(10)->hours();
        $this->assertSame(5, app(ShopKeeper::class)->wares($this->place)->first()->stock, 'never past the maximum');
    }

    public function test_a_full_shelf_banks_no_restocks(): void
    {
        $item = Item::factory()->physical()->create();
        $row = $this->listing($item);

        $this->travel(20)->hours();
        app(ShopKeeper::class)->wares($this->place);
        $this->assertSame(5, $row->fresh()->stock);

        $this->buy($item, 5);
        $this->assertSame(0, $row->fresh()->stock);
        $this->assertSame(0, app(ShopKeeper::class)->wares($this->place)->first()->stock, 'the idle hours were not saved up');
    }

    public function test_no_maximum_is_unlimited_and_never_runs_out(): void
    {
        $item = Item::factory()->physical()->create();
        $this->listing($item, ['max_stock' => null, 'stock' => 0]);

        $this->assertSame(50, $this->buy($item, 50));
    }

    public function test_saving_the_editor_keeps_a_depleted_shelf_and_starts_a_new_one_full(): void
    {
        $item = Item::factory()->physical()->create();
        $other = Item::factory()->physical()->create();
        $row = $this->listing($item);
        $this->buy($item, 4);

        $row2 = ['price' => 10, 'max_stock' => 3, 'restock_amount' => 0, 'restock_hours' => null];
        FeatureEditor::apply($this->place, [['item_id' => $item->id] + $row2, ['item_id' => $other->id, 'price' => null, 'max_stock' => 8, 'restock_amount' => 1, 'restock_hours' => 1]], []);

        $this->assertSame(1, $row->fresh()->stock, 'kept, and already under the lowered maximum');
        $this->assertSame(8, ShopItem::query()->where('item_id', $other->id)->value('stock'), 'new listings start full');

        FeatureEditor::apply($this->place, [['item_id' => $other->id, 'price' => null, 'max_stock' => 8, 'restock_amount' => 1, 'restock_hours' => 1]], []);
        $this->assertNull(ShopItem::query()->where('item_id', $item->id)->first(), 'removed');
    }

    private function carry(Item $item, int $quantity = 1, array $extra = []): InventoryItem
    {
        return InventoryItem::create(['character_id' => $this->character->id, 'item_id' => $item->id, 'quantity' => $quantity, ...$extra]);
    }

    private function sell(InventoryItem $row, int $quantity): int|CityRefusal
    {
        return app(ShopKeeper::class)->sell($this->character->fresh(), $this->place, $row->id, $quantity);
    }

    public function test_selling_pays_the_percentage_of_the_stock_price_and_what_is_sold_is_gone(): void
    {
        $item = Item::factory()->physical()->create(['base_cost' => 100, 'stackable' => true]);
        LocationBuy::create(['location_id' => $this->place->id, 'item_id' => $item->id, 'percent' => 40]);
        $row = $this->carry($item, 5);

        $this->assertSame(80, $this->sell($row, 2), '2 at 40% of 100');
        $this->assertSame(3, $row->fresh()->quantity);
        $this->assertSame(1080, $this->character->fresh()->creds);
        $this->assertSame(Reason::Sale, WalletEntry::query()->sole()->reason);

        $this->assertSame(120, $this->sell($row->fresh(), 3));
        $this->assertNull(InventoryItem::query()->find($row->id), 'the whole pile went');
        $this->assertSame(0, ShopItem::query()->count(), 'and it did not join any shelf');
    }

    public function test_a_listing_pays_the_full_stock_price_unless_told_otherwise(): void
    {
        $item = Item::factory()->physical()->create(['base_cost' => 100]);
        LocationBuy::create(['location_id' => $this->place->id, 'item_id' => $item->id]);

        $this->assertSame(100, $this->sell($this->carry($item), 1));
    }

    public function test_a_limit_is_refused_not_reduced_and_resets_with_the_cycle(): void
    {
        $item = Item::factory()->physical()->create(['base_cost' => 10, 'stackable' => true]);
        LocationBuy::create(['location_id' => $this->place->id, 'item_id' => $item->id, 'max_quantity' => 3, 'window_started_at' => now()]);
        $row = $this->carry($item, 10);

        $this->assertSame(CityRefusal::WontTakeMore, $this->sell($row, 4));
        $this->assertSame(20, $this->sell($row, 2));
        $this->assertSame(CityRefusal::WontTakeMore, $this->sell($row->fresh(), 2), 'one left of three');
        $this->assertSame(10, $this->sell($row->fresh(), 1));
        $this->assertSame(CityRefusal::WontTakeMore, $this->sell($row->fresh(), 1));

        $this->travel(9)->hours();
        $this->assertSame(30, $this->sell($row->fresh(), 3), 'a new window, a new limit');
    }

    public function test_it_will_not_take_what_it_does_not_list_or_what_is_not_in_your_hands(): void
    {
        $listed = Item::factory()->physical()->unstackable()->create(['base_cost' => 100]);
        $unlisted = Item::factory()->physical()->create(['base_cost' => 100]);
        LocationBuy::create(['location_id' => $this->place->id, 'item_id' => $listed->id]);

        $this->assertSame(CityRefusal::CannotSell, $this->sell($this->carry($unlisted), 1));
        $this->assertSame(CityRefusal::CannotSell, $this->sell($this->carry($listed, 1, ['installed_at' => now()]), 1), 'installed in a body');

        $deck = $this->carry($listed);
        $this->character->forceFill(['equipped_deck_id' => $deck->id])->save();
        $this->assertSame(CityRefusal::CannotSell, $this->sell($deck, 1), 'the deck you carry');

        $this->assertSame(CityRefusal::CannotSell, $this->sell($this->carry($listed), 5), 'more than you have');
        $this->assertSame(0, WalletEntry::query()->count());
    }

    public function test_a_container_with_something_in_it_is_not_taken(): void
    {
        $shard = Item::factory()->physical()->holds('data')->create(['base_cost' => 100]);
        LocationBuy::create(['location_id' => $this->place->id, 'item_id' => $shard->id]);
        $row = $this->carry($shard);
        $this->carry(Item::factory()->data()->create(), 1, ['parent_id' => $row->id]);

        $this->assertSame(CityRefusal::CannotSell, $this->sell($row, 1));
    }

    public function test_the_page_shows_stock_and_what_they_buy_and_selling_needs_the_body_there(): void
    {
        $item = Item::factory()->physical()->create(['name' => 'Thing', 'base_cost' => 100]);
        $this->listing($item, ['stock' => 0]);
        LocationBuy::create(['location_id' => $this->place->id, 'item_id' => $item->id]);
        $row = $this->carry($item);

        $this->actingAs($this->character->user)->get(route('city.place', 'stall'))
            ->assertOk()->assertSeeText('Sold out')->assertSeeText('They buy');

        $this->character->forceFill(['district_id' => District::query()->where('slug', 'dockside')->value('id')])->save();
        $this->assertSame(CityRefusal::NotHere, $this->sell($row, 1));
    }

    public function test_the_editor_saves_stock_columns_and_a_buy_list_and_a_delete_counts_both(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $item = Item::factory()->physical()->create(['base_cost' => 9]);

        $this->actingAs($admin)->put(route('admin.locations.update', $this->place), [
            'district_id' => $this->place->district_id, 'name' => 'Stall', 'map_x' => 50, 'map_y' => 50, 'discovery' => 'open',
            'shop' => [['item_id' => $item->id, 'price' => '', 'max_stock' => '6', 'restock_amount' => '2', 'restock_hours' => '4']],
            'buys' => [['item_id' => $item->id, 'percent' => '', 'max_quantity' => '7'], ['item_id' => '', 'percent' => '3']],
        ])->assertSessionHasNoErrors();

        $row = ShopItem::query()->sole();
        $this->assertSame([6, 6, 2, 4], [$row->max_stock, $row->stock, $row->restock_amount, $row->restock_hours]);
        $buy = LocationBuy::query()->sole();
        $this->assertSame([100, 7], [$buy->percent, $buy->max_quantity], 'blank percent is 100');

        $counts = DeletionImpact::forItem($item)->counts();
        $this->assertArrayHasKey('shop_listings', $counts);
        $this->assertArrayHasKey('shop_buys', $counts);
    }
}
