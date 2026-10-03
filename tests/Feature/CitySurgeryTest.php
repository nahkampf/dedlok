<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Checks\CheckRoller;
use App\City\CityClock;
use App\City\CityRefusal;
use App\City\Discovery;
use App\City\Surgeon;
use App\City\SurgeryOperation;
use App\Cyberspace\Navigator;
use App\Cyberspace\TravelRefusal;
use App\Implants\BodyRegion;
use App\Models\Character;
use App\Models\CityActivity;
use App\Models\District;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Location;
use App\Models\Notification;
use App\Models\User;
use App\Models\WalletEntry;
use App\Stats\InjuryLedger;
use App\Stats\Resolver;
use App\Wallet\Reason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The Back-Alley Clinic fits tier 1 implants into the body, arms and legs for
 * 150 a tier and takes them out for 60. Its surgeon is rated 22 against a
 * difficulty of 20 for a tier 1 fitting, and a fitting takes 300 seconds a tier.
 */
final class CitySurgeryTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    private Location $clinic;

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['archetype' => 3, 'creds' => 1000],
        ));
        $this->clinic = Location::query()->where('slug', 'back-alley-clinic')->firstOrFail();
        Discovery::unlock($this->character, $this->clinic);
    }

    private function die(float $roll): void
    {
        $this->app->instance(CheckRoller::class, CheckRoller::fromConfig(static fn (): float => $roll));
    }

    private function implant(BodyRegion $region = BodyRegion::Arms, int $tier = 1): InventoryItem
    {
        $item = Item::factory()->physical()->create(['implant_region' => $region->value, 'stackable' => false, 'tier' => $tier]);

        return InventoryItem::create(['character_id' => $this->character->id, 'item_id' => $item->id, 'quantity' => 1]);
    }

    private function begin(InventoryItem $row, SurgeryOperation $op = SurgeryOperation::Install): CityActivity|CityRefusal
    {
        return app(Surgeon::class)->begin($this->character->fresh(), $this->clinic, $row->load('item'), $op);
    }

    private function settle(): array
    {
        $this->travel(2)->hours();

        return app(CityClock::class)->settle($this->character->fresh());
    }

    public function test_a_fitting_charges_up_front_and_takes_time(): void
    {
        $row = $this->implant();

        $quote = app(Surgeon::class)->quote($this->character, $this->clinic, $row->load('item'), SurgeryOperation::Install);
        $this->assertSame([150, 300, 1], [$quote['fee'], $quote['seconds'], $quote['tier']]);

        $this->assertInstanceOf(CityActivity::class, $this->begin($row));
        $this->assertSame(850, $this->character->fresh()->creds);
        $this->assertSame([-150, Reason::Surgery], [WalletEntry::query()->sole()->amount, WalletEntry::query()->sole()->reason]);
        $this->assertNull($row->fresh()->installed_at, 'not yet');

        $this->travel(299)->seconds();
        $this->assertSame([], app(CityClock::class)->settle($this->character->fresh()));
        $this->assertNull($row->fresh()->installed_at);
    }

    public function test_a_good_roll_fits_the_implant(): void
    {
        $this->die(0.0);
        $row = $this->implant();
        $this->begin($row);

        $this->assertSame('surgery_installed', $this->settle()[0][0]);
        $this->assertNotNull($row->fresh()->installed_at);
        $this->assertNull(CityActivity::query()->find($this->character->id));
    }

    /** A bad roll costs injury and the implant is rejected: it stays in the stash. */
    public function test_a_bad_roll_hurts_and_rejects_the_implant(): void
    {
        $this->die(0.9999);
        $row = $this->implant(BodyRegion::Arms);
        $this->begin($row);

        $this->assertSame('surgery_failed', $this->settle()[0][0]);
        $this->assertNull($row->fresh()->installed_at, 'rejected, and still in the stash');
        $this->assertNotNull(InventoryItem::query()->find($row->id));
        $this->assertSame(-2.0, (new InjuryLedger)->carried($this->character->fresh(), InjuryLedger::TRAUMA), 'an arm is physical trauma, 2 a tier');
    }

    public function test_the_quoted_chance_is_the_chance_the_roll_uses(): void
    {
        $row = $this->implant();
        $quote = app(Surgeon::class)->quote($this->character, $this->clinic, $row->load('item'), SurgeryOperation::Install);

        $this->assertEqualsWithDelta(CheckRoller::fromConfig()->chance(22.0, 20.0, 'surgery'), $quote['chance'], 1e-9);
    }

    public function test_taking_one_out_is_a_fee_and_time_and_no_roll(): void
    {
        $this->die(0.9999);
        $row = $this->implant();
        $row->forceFill(['installed_at' => now()])->save();

        $this->assertInstanceOf(CityActivity::class, $this->begin($row, SurgeryOperation::Remove));
        $this->assertSame(940, $this->character->fresh()->creds, '60 a tier');

        $this->assertSame('surgery_removed', $this->settle()[0][0]);
        $this->assertNull($row->fresh()->installed_at);
        $this->assertSame(0.0, (new InjuryLedger)->carried($this->character->fresh(), InjuryLedger::TRAUMA));
    }

    public function test_a_clinic_only_does_what_it_offers(): void
    {
        $this->assertSame(CityRefusal::NotOffered, $this->begin($this->implant(BodyRegion::Head)), 'not the head');
        $this->assertSame(CityRefusal::NotOffered, $this->begin($this->implant(BodyRegion::Spine)), 'not the spine');
        $this->assertSame(CityRefusal::NotOffered, $this->begin($this->implant(BodyRegion::Arms, tier: 2)), 'not tier 2');

        $other = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        $theirs = InventoryItem::create(['character_id' => $other->id, 'item_id' => $this->implant()->item_id, 'quantity' => 1]);
        $this->assertSame(CityRefusal::CannotOperate, $this->begin($theirs), 'not yours');
        $this->assertSame(1000, $this->character->fresh()->creds);
        $this->assertSame(0, WalletEntry::query()->count());
    }

    public function test_a_full_region_a_busy_body_and_a_light_wallet_are_refused(): void
    {
        $this->die(0.0);
        // The body holds four.
        foreach (range(1, 4) as $_) {
            $this->implant(BodyRegion::Body)->forceFill(['installed_at' => now()])->save();
        }
        $this->assertSame(CityRefusal::NoRoom, $this->begin($this->implant(BodyRegion::Body)));

        $arm = $this->implant();
        $this->assertInstanceOf(CityActivity::class, $this->begin($arm));
        $this->assertSame(CityRefusal::Busy, $this->begin($this->implant()));

        CityActivity::query()->delete();
        $this->character->forceFill(['creds' => 10])->save();
        $this->assertSame(CityRefusal::CannotAfford, $this->begin($this->implant()));
    }

    public function test_nobody_leaves_the_table_and_a_body_on_it_cannot_jack_in(): void
    {
        $this->begin($this->implant());

        $this->actingAs($this->character->user)->post(route('city.cancel'))->assertSessionHas('error');
        $this->assertNotNull(CityActivity::query()->find($this->character->id));

        $result = Navigator::fromConfig(app(Resolver::class))->jackIn($this->character->fresh());
        $this->assertSame(TravelRefusal::UnderTheKnife, $result->refusal);
    }

    public function test_the_sweep_settles_and_tells_the_player(): void
    {
        $this->die(0.0);
        $this->begin($this->implant());
        $this->travel(2)->hours();

        $this->artisan('city:settle')->assertSuccessful();

        $this->assertSame(1, Notification::query()->where('user_id', $this->character->user_id)->count());
    }

    public function test_the_place_page_quotes_and_the_screen_starts_it(): void
    {
        $row = $this->implant();
        $as = $this->actingAs($this->character->user);

        $as->get(route('city.place', 'back-alley-clinic'))->assertOk()->assertSeeText('Surgery')->assertSeeText('150 ¤')->assertSeeText('% to take');
        $as->post(route('city.operate', ['back-alley-clinic', $row->id, 'install']))->assertRedirect();

        $this->assertNotNull(CityActivity::query()->find($this->character->id));
        $as->get(route('city.index'))->assertOk()->assertSeeText('operating table');
    }

    public function test_the_editor_gives_a_clinic_surgery_and_removing_it_takes_it_away(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $base = ['district_id' => District::startId(), 'name' => 'Better Clinic', 'map_x' => 50, 'map_y' => 50, 'discovery' => 'open'];
        $place = Location::create($base + ['slug' => 'better-clinic']);

        $this->actingAs($admin)->put(route('admin.locations.update', $place), $base + ['surgery' => [
            'offered' => '1', 'rating' => '45', 'max_tier' => '2', 'install_price' => '300', 'removal_price' => '100', 'regions' => ['head', 'spine'],
        ]])->assertSessionHasNoErrors();

        $surgery = $place->fresh()->surgery;
        $this->assertSame([45, 2, 300], [$surgery->rating, $surgery->max_tier, $surgery->install_price]);
        $this->assertEqualsCanonicalizing([BodyRegion::Head, BodyRegion::Spine], $surgery->regions());

        $this->actingAs($admin)->put(route('admin.locations.update', $place), $base)->assertSessionHasNoErrors();
        $this->assertNull($place->fresh()->surgery);
        $this->assertSame(0, DB::table('clinic_surgery_regions')->where('location_id', $place->id)->count());
    }
}
