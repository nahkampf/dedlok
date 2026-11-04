<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\City\CityClock;
use App\City\CityRefusal;
use App\City\Explorer;
use App\City\Traveller;
use App\City\TravelMode;
use App\Cyberspace\Navigator;
use App\Cyberspace\TravelRefusal;
use App\Models\Character;
use App\Models\CityActivity;
use App\Models\District;
use App\Models\Notification;
use App\Models\WalletEntry;
use App\Stats\Resolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Dockside to Downtown goes by Old Town and no other way that short: three
 * districts, two passes. At the base rate each pass is 30 seconds, and a pod
 * halves that.
 */
final class CityTravelTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['archetype' => 3, 'creds' => 200],
        ));
        $this->character->forceFill(['district_id' => $this->district('dockside')->id])->save();
    }

    private function district(string $slug): District
    {
        return District::query()->where('slug', $slug)->firstOrFail();
    }

    private function in(): string
    {
        return District::query()->findOrFail($this->character->fresh()->district_id)->slug;
    }

    private function traveller(): Traveller
    {
        return app(Traveller::class);
    }

    private function settle(): array
    {
        return app(CityClock::class)->settle($this->character->fresh());
    }

    public function test_a_walk_costs_nothing_and_takes_a_pass_in_each_district_but_the_last(): void
    {
        $quote = $this->traveller()->quote($this->character, $this->district('downtown'));

        $this->assertIsArray($quote);
        $this->assertCount(3, $quote['route']);
        $this->assertSame(60, $quote['walk'], 'two passes of 30');
        $this->assertSame(30, $quote['pod'], 'half of each');
        $this->assertSame(75, $quote['fare'], '25 a district, the destination counting');

        $this->assertInstanceOf(CityActivity::class, $this->traveller()->begin($this->character, $this->district('downtown'), TravelMode::Walk));
        $this->assertSame(200, $this->character->fresh()->creds);
        $this->assertSame('dockside', $this->in(), 'the first pass is in the origin');
    }

    public function test_you_move_along_the_route_pass_by_pass(): void
    {
        $this->freezeTime();

        $this->traveller()->begin($this->character, $this->district('downtown'), TravelMode::Walk);

        $this->travel(29)->seconds();
        $this->settle();
        $this->assertSame('dockside', $this->in());

        $this->travel(2)->seconds();
        $this->settle();
        $this->assertSame('old-town', $this->in(), 'the second pass is in the middle district');

        $this->travel(30)->seconds();
        $messages = $this->settle();
        $this->assertSame('downtown', $this->in());
        $this->assertNull(CityActivity::query()->find($this->character->id));
        $this->assertSame('travel_arrived', $messages[0][0]);
    }

    /** One settle call walks every pass that has run, each beginning when the last matured. */
    public function test_one_settle_catches_up_the_whole_trip(): void
    {
        $this->traveller()->begin($this->character, $this->district('downtown'), TravelMode::Walk);

        $this->travel(3)->hours();
        $this->settle();

        $this->assertSame('downtown', $this->in());
    }

    public function test_settling_twice_changes_nothing_the_second_time(): void
    {
        $this->traveller()->begin($this->character, $this->district('downtown'), TravelMode::Walk);
        $this->travel(2)->minutes();

        $this->assertCount(1, $this->settle());
        $this->assertSame([], $this->settle());
    }

    public function test_a_pod_charges_up_front_and_halves_each_pass(): void
    {
        $activity = $this->traveller()->begin($this->character, $this->district('downtown'), TravelMode::Pod);

        $this->assertSame(125, $this->character->fresh()->creds);
        $this->assertSame(75, $activity->paid);
        $this->assertSame(-75, WalletEntry::query()->sole()->amount);

        $this->travel(16)->seconds();
        $this->settle();
        $this->assertSame('old-town', $this->in(), 'a pass is 15 seconds by pod');
    }

    /** From docs/CITY.md: cancelled in the middle district, you paid for two and get 25 back. */
    public function test_cancelling_waits_for_the_pass_and_refunds_the_districts_not_reached(): void
    {
        $this->traveller()->begin($this->character, $this->district('downtown'), TravelMode::Pod);
        $this->travel(16)->seconds();
        $this->settle();
        $this->assertSame('old-town', $this->in());

        $this->assertTrue($this->traveller()->cancel($this->character->fresh()));
        $this->assertSame('old-town', $this->in(), 'nothing moves until the pass ends');
        $this->assertSame(125, $this->character->fresh()->creds);

        $this->travel(15)->seconds();
        $messages = $this->settle();

        $this->assertSame('old-town', $this->in(), 'you stay where you were, not on to the destination');
        $this->assertSame('travel_stopped', $messages[0][0]);
        $this->assertSame(150, $this->character->fresh()->creds, '75 paid, 25 back');
    }

    public function test_cancelling_in_the_first_pass_refunds_everything_ahead(): void
    {
        $this->traveller()->begin($this->character, $this->district('downtown'), TravelMode::Pod);
        $this->traveller()->cancel($this->character->fresh());

        $this->travel(1)->minutes();
        $this->settle();

        $this->assertSame('dockside', $this->in());
        $this->assertSame(175, $this->character->fresh()->creds, '75 paid, 50 back');
    }

    public function test_a_walk_has_nothing_to_refund(): void
    {
        $this->traveller()->begin($this->character, $this->district('downtown'), TravelMode::Walk);
        $this->traveller()->cancel($this->character->fresh());
        $this->travel(1)->minutes();
        $this->settle();

        $this->assertSame(200, $this->character->fresh()->creds);
        $this->assertSame(0, WalletEntry::query()->count());
    }

    public function test_the_refusals(): void
    {
        $to = $this->district('downtown');

        $this->assertSame(CityRefusal::AlreadyThere, $this->traveller()->begin($this->character, $this->district('dockside'), TravelMode::Walk));

        $this->character->forceFill(['creds' => 10])->save();
        $this->assertSame(CityRefusal::CannotAfford, $this->traveller()->begin($this->character->fresh(), $to, TravelMode::Pod));
        $this->assertSame(10, $this->character->fresh()->creds, 'nothing charged');

        $this->assertInstanceOf(CityActivity::class, $this->traveller()->begin($this->character->fresh(), $to, TravelMode::Walk));
        $this->assertSame(CityRefusal::Busy, $this->traveller()->begin($this->character->fresh(), $to, TravelMode::Walk));

        $other = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        $other->forceFill(['grid_x' => 1, 'grid_y' => 1])->save();
        $this->assertSame(CityRefusal::JackedIn, $this->traveller()->begin($other->fresh(), $to, TravelMode::Walk));
    }

    public function test_a_district_removed_mid_trip_ends_it_where_you_stand(): void
    {
        $this->traveller()->begin($this->character, $this->district('downtown'), TravelMode::Pod);
        // Unlink and drop the destination's links first, as an editor would have to.
        DB::table('district_links')->where('district_a_id', $this->district('downtown')->id)
            ->orWhere('district_b_id', $this->district('downtown')->id)->delete();
        // A district holding a safehouse cannot be deleted from under it, so the editor would remove those first too.
        DB::table('safehouses')->where('district_id', $this->district('downtown')->id)->delete();
        $this->district('downtown')->delete();

        $this->travel(5)->minutes();
        $this->settle();

        $this->assertSame('old-town', $this->in());
        $this->assertSame(150, $this->character->fresh()->creds, 'the unreached district is refunded');
    }

    public function test_the_sweep_settles_and_tells_the_player(): void
    {
        $this->traveller()->begin($this->character, $this->district('downtown'), TravelMode::Walk);
        $this->travel(5)->minutes();

        $this->artisan('city:settle')->expectsOutputToContain('1 character(s) settled.')->assertSuccessful();

        $this->assertSame('downtown', $this->in());
        $this->assertSame(1, Notification::query()->where('user_id', $this->character->user_id)->count());
    }

    public function test_the_screens_start_and_cancel_a_trip(): void
    {
        $this->actingAs($this->character->user)
            ->post(route('city.travel', 'downtown'), ['mode' => 'walk'])
            ->assertRedirect();
        $this->assertNotNull(CityActivity::query()->find($this->character->id));

        $this->actingAs($this->character->user)->get(route('city.index'))
            ->assertOk()->assertSeeText('Under way');

        $this->actingAs($this->character->user)->post(route('city.cancel'))->assertRedirect();
        $this->assertTrue((bool) CityActivity::query()->find($this->character->id)->cancelling);
    }

    public function test_nobody_jacks_in_while_travelling_or_exploring(): void
    {
        $this->traveller()->begin($this->character, $this->district('downtown'), TravelMode::Walk);

        $result = Navigator::fromConfig(app(Resolver::class))->jackIn($this->character->fresh());
        $this->assertSame(TravelRefusal::BusyInTheCity, $result->refusal);

        CityActivity::query()->delete();
        app(Explorer::class)->begin($this->character->fresh());

        $result = Navigator::fromConfig(app(Resolver::class))->jackIn($this->character->fresh());
        $this->assertSame(TravelRefusal::BusyInTheCity, $result->refusal);
    }

    public function test_a_trip_is_stopped_not_cancelled_and_an_explore_is_still_cancelled(): void
    {
        $this->traveller()->begin($this->character, $this->district('downtown'), TravelMode::Walk);

        $this->actingAs($this->character->user)->get(route('city.index'))
            ->assertOk()->assertSeeText('Stop travelling');

        CityActivity::query()->delete();
        app(Explorer::class)->begin($this->character->fresh());

        $this->actingAs($this->character->user)->get(route('city.district', 'dockside'))
            ->assertOk()->assertSeeText('Cancel')->assertDontSeeText('Stop travelling');
    }

    public function test_each_pass_walked_tires_and_each_pass_in_a_pod_eases(): void
    {
        $this->traveller()->begin($this->character, $this->district('downtown'), TravelMode::Walk);
        $this->travel(2)->minutes();
        $this->settle();

        // Two passes (Dockside, Old Town), five each.
        $this->assertSame(10, $this->character->fresh()->current_fatigue);

        // Back to the start by query: the instance in hand predates the walk and would not see the move.
        Character::query()->whereKey($this->character->id)->update(['district_id' => $this->district('dockside')->id]);
        $this->traveller()->begin($this->character->fresh(), $this->district('downtown'), TravelMode::Pod);
        $this->travel(2)->minutes();
        $this->settle();

        $this->assertSame(8, $this->character->fresh()->current_fatigue);
    }
}
