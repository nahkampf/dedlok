<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Admin\DeletionImpact;
use App\City\CityRefusal;
use App\City\Clinic;
use App\City\ClinicService;
use App\City\Discovery;
use App\City\ShopKeeper;
use App\Models\Character;
use App\Models\CityActivity;
use App\Models\District;
use App\Models\Item;
use App\Models\Location;
use App\Models\ShopItem;
use App\Models\User;
use App\Models\WalletEntry;
use App\Stats\InjuryLedger;
use App\Wallet\Reason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/**
 * Shops and clinics at a place in Old Town. The Back-Alley Clinic arrives by
 * migration with placeholder prices (4 a hit point, 6 a point of Synaptic
 * Integrity, 80 a point of injury).
 */
final class CityPlaceTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    private Character $character;

    private Location $shop;

    private Location $clinic;

    protected function setUp(): void
    {
        parent::setUp();

        config(['decks.onboard_storage' => 20]);

        $this->character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['archetype' => 3, 'creds' => 1000],
        ));

        $this->shop = Location::create([
            'district_id' => District::startId(), 'slug' => 'corner-shop', 'name' => 'Corner Shop',
            'map_x' => 50, 'map_y' => 50, 'discovery' => 'open',
        ]);

        $this->clinic = Location::query()->where('slug', 'back-alley-clinic')->firstOrFail();
        Discovery::unlock($this->character, $this->clinic);
    }

    private function stock(Item $item, ?int $price = null): void
    {
        ShopItem::create(['location_id' => $this->shop->id, 'item_id' => $item->id, 'price' => $price]);
    }

    private function buy(int $itemId, int $quantity = 1): int|CityRefusal
    {
        return app(ShopKeeper::class)->buy($this->character->fresh(), $this->shop, $itemId, $quantity);
    }

    public function test_a_purchase_charges_grants_and_is_recorded(): void
    {
        $item = Item::factory()->physical()->create(['base_cost' => 50]);
        $this->stock($item);

        $this->assertSame(2, $this->buy($item->id, 2));

        $this->assertSame(900, $this->character->fresh()->creds);
        $this->assertSame(2, (int) $this->character->inventory()->where('item_id', $item->id)->sum('quantity'));
        $entry = WalletEntry::query()->sole();
        $this->assertSame([-100, Reason::Shop], [$entry->amount, $entry->reason]);
    }

    public function test_the_rows_own_price_beats_the_items_and_ignores_the_market_flag(): void
    {
        $item = Item::factory()->physical()->create(['base_cost' => 50, 'on_market' => false]);
        $this->stock($item, 7);

        $this->assertSame(1, $this->buy($item->id));
        $this->assertSame(993, $this->character->fresh()->creds);
    }

    public function test_the_shops_own_door_never_touches_the_markets_tables(): void
    {
        $item = Item::factory()->physical()->create(['base_cost' => 5]);
        $this->stock($item);
        $this->buy($item->id);

        $this->assertSame(0, DB::table('market_purchases')->count());
        $this->assertSame(0, DB::table('market_stock')->count());
    }

    public function test_it_refuses_what_it_does_not_sell_and_what_you_cannot_afford(): void
    {
        $dear = Item::factory()->physical()->create(['base_cost' => 5000]);
        $this->stock($dear);

        $this->assertSame(CityRefusal::CannotAfford, $this->buy($dear->id));
        $this->assertSame(CityRefusal::NotSold, $this->buy(Item::factory()->physical()->create()->id));
        $this->assertSame(1000, $this->character->fresh()->creds);
        $this->assertSame(0, WalletEntry::query()->count());
    }

    public function test_data_that_does_not_fit_the_deck_is_refused_before_any_money_moves(): void
    {
        $this->equipReadyDeck($this->character);
        $file = Item::factory()->data()->size(15)->create(['base_cost' => 10]);
        $this->stock($file);

        $this->assertSame(1, $this->buy($file->id));
        $this->assertSame(CityRefusal::NoStorage, $this->buy($file->id));
        $this->assertSame(990, $this->character->fresh()->creds, 'only the first was charged');
    }

    public function test_you_must_be_in_the_district_and_not_busy_or_jacked_in(): void
    {
        $item = Item::factory()->physical()->create(['base_cost' => 5]);
        $this->stock($item);

        CityActivity::query()->create(['character_id' => $this->character->id, 'kind' => 'explore', 'step' => 0, 'paid' => 0, 'cancelling' => false, 'pass_ends_at' => now()->addHour()]);
        $this->assertSame(CityRefusal::Busy, $this->buy($item->id));
        CityActivity::query()->delete();

        $this->character->forceFill(['district_id' => District::query()->where('slug', 'dockside')->value('id')])->save();
        $this->assertSame(CityRefusal::NotHere, $this->buy($item->id));

        $this->character->forceFill(['district_id' => District::startId(), 'grid_x' => 1, 'grid_y' => 1])->save();
        $this->assertSame(CityRefusal::JackedIn, $this->buy($item->id));
    }

    public function test_a_hidden_place_is_the_same_404_as_one_that_does_not_exist_and_cannot_be_acted_on(): void
    {
        $hidden = Location::query()->where('slug', 'the-night-market')->firstOrFail();
        $item = Item::factory()->physical()->create(['base_cost' => 5]);
        ShopItem::create(['location_id' => $hidden->id, 'item_id' => $item->id]);

        $as = $this->actingAs($this->character->user);

        $as->get(route('city.place', 'the-night-market'))->assertNotFound();
        $as->get(route('city.place', 'no-such-place'))->assertNotFound();
        $as->post(route('city.buy', ['the-night-market', $item->id]), ['quantity' => 1])->assertNotFound();
        $this->assertSame(1000, $this->character->fresh()->creds);
    }

    public function test_a_known_place_in_another_district_can_be_read_but_not_used(): void
    {
        $this->character->forceFill(['district_id' => District::query()->where('slug', 'dockside')->value('id')])->save();

        $this->actingAs($this->character->user)->get(route('city.place', 'corner-shop'))
            ->assertOk()->assertSeeText('You are not in Old Town');
    }

    public function test_the_clinic_charges_exactly_what_it_quoted_and_never_heals_past_the_maximum(): void
    {
        $max = $this->character->maximumFor('current_hitpoints');
        $this->character->forceFill(['current_hitpoints' => $max - 10])->save();
        $character = $this->character->fresh();

        $quote = collect(app(Clinic::class)->quote($character, $this->clinic))->firstWhere('service', ClinicService::Hitpoints);
        $this->assertSame(10, $quote['points']);
        $this->assertSame(40, $quote['total']);

        $this->assertSame(40, app(Clinic::class)->treat($character, $this->clinic, ClinicService::Hitpoints));
        $this->assertSame($max, $this->character->fresh()->current_hitpoints);
        $this->assertSame(960, $this->character->fresh()->creds);
        $this->assertSame(Reason::Clinic, WalletEntry::query()->sole()->reason);

        $this->assertSame(CityRefusal::NothingToTreat, app(Clinic::class)->treat($this->character->fresh(), $this->clinic, ClinicService::Hitpoints));
        $this->assertSame(960, $this->character->fresh()->creds, 'nothing owed, nothing charged');
    }

    public function test_an_injury_is_mended_by_the_point_and_restoring_comes_after(): void
    {
        (new InjuryLedger)->inflict($this->character, InjuryLedger::NEURAL, 3);
        $character = $this->character->fresh();

        $this->assertSame(240, app(Clinic::class)->treat($character, $this->clinic, ClinicService::NeuralDamage), '3 points at 80');
        $this->assertSame(0.0, (new InjuryLedger)->carried($this->character->fresh(), InjuryLedger::NEURAL));
        $this->assertSame(CityRefusal::NothingToTreat, app(Clinic::class)->treat($this->character->fresh(), $this->clinic, ClinicService::NeuralDamage));
    }

    public function test_you_cannot_treat_what_you_cannot_pay_for(): void
    {
        $this->character->forceFill(['creds' => 3, 'current_hitpoints' => 1])->save();

        $this->assertSame(CityRefusal::CannotAfford, app(Clinic::class)->treat($this->character->fresh(), $this->clinic, ClinicService::Hitpoints));
        $this->assertSame(1, $this->character->fresh()->current_hitpoints);
    }

    public function test_the_screens_and_the_district_page_show_the_features(): void
    {
        $item = Item::factory()->physical()->create(['name' => 'Spare Part', 'base_cost' => 12]);
        $this->stock($item);

        $as = $this->actingAs($this->character->user);
        $as->get(route('city.place', 'corner-shop'))->assertOk()->assertSeeText('Spare Part')->assertSeeText('12 ¤');
        $as->get(route('city.place', 'back-alley-clinic'))->assertOk()->assertSeeText('Restore hit points');
        $as->get(route('city.district', 'old-town'))->assertOk()->assertSeeText('Shop')->assertSeeText('Clinic');

        $as->post(route('city.buy', ['corner-shop', $item->id]), ['quantity' => 3])->assertRedirect();
        $this->assertSame(964, $this->character->fresh()->creds);
    }

    public function test_the_editor_replaces_a_places_shop_and_clinic_and_a_delete_names_the_listings(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $item = Item::factory()->physical()->create(['base_cost' => 9]);

        $this->actingAs($admin)->put(route('admin.locations.update', $this->shop), [
            'district_id' => $this->shop->district_id, 'name' => 'Corner Shop', 'map_x' => 50, 'map_y' => 50, 'discovery' => 'open',
            'shop' => [['item_id' => $item->id, 'price' => ''], ['item_id' => '', 'price' => '5']],
            'clinic' => ['hitpoints' => '3', 'neural_damage' => ''],
        ])->assertSessionHasNoErrors();

        $row = ShopItem::query()->where('location_id', $this->shop->id)->sole();
        $this->assertNull($row->price, 'blank means the item\'s own cost');
        $this->assertSame(9, $row->unitPrice());
        $this->assertSame(['hitpoints'], $this->shop->clinicServices()->get()->map(fn ($o) => $o->service->value)->all(), 'a blank price is not offered');
        $this->assertArrayHasKey('shop_listings', DeletionImpact::forItem($item)->counts());
    }
}
