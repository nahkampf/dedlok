<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Checks\CheckRoller;
use App\City\CityClock;
use App\City\CityRefusal;
use App\City\Explorer;
use App\Models\Character;
use App\Models\CityActivity;
use App\Models\District;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Knightsbridge Projects holds two hidden places: the Stairwell Market
 * (Trivial) and the Sub-Basement (Secretive).
 */
final class CityExploreTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['archetype' => 3],
        ));
        $this->character->forceFill([
            'district_id' => District::query()->where('slug', 'knightsbridge-projects')->value('id'),
        ])->save();
    }

    private function die(float $roll): void
    {
        $this->app->instance(CheckRoller::class, CheckRoller::fromConfig(static fn (): float => $roll));
    }

    private function explore(): array
    {
        app(Explorer::class)->begin($this->character->fresh());
        $this->travel(5)->minutes();

        return app(CityClock::class)->settle($this->character->fresh());
    }

    private function found(): array
    {
        return DB::table('character_locations')->where('character_id', $this->character->id)
            ->join('locations', 'locations.id', '=', 'character_locations.location_id')->pluck('slug')->all();
    }

    /** Several rolls succeed in one pass, and the HARDEST wins. */
    public function test_a_pass_finds_at_most_one_and_the_hardest_wins(): void
    {
        $this->die(0.0);

        $messages = $this->explore();

        $this->assertSame(['the-sub-basement'], $this->found());
        $this->assertSame('explore_found', $messages[0][0]);
        $this->assertNull(CityActivity::query()->find($this->character->id), 'one pass, then it is done');
    }

    public function test_the_easier_one_waits_for_another_pass(): void
    {
        $this->die(0.0);
        $this->explore();
        $this->explore();

        $this->assertEqualsCanonicalizing(['the-sub-basement', 'the-stairwell-market'], $this->found());
    }

    /** A failed pass and a pass with nothing to roll against are the same: silence. */
    public function test_nothing_found_is_silent_whether_or_not_anything_was_there(): void
    {
        $this->die(0.9999);
        $failed = $this->explore();

        $this->character->forceFill(['district_id' => District::query()->where('slug', 'little-moscow')->value('id')])->save();
        $nothingThere = $this->explore();

        $this->assertSame($failed, $nothingThere);
        $this->assertSame([], $failed, 'finding nothing says nothing');
        $this->assertSame([], $this->found());
    }

    public function test_it_is_not_refused_for_there_being_nothing_left(): void
    {
        $this->character->forceFill(['district_id' => District::query()->where('slug', 'little-moscow')->value('id')])->save();

        $this->assertInstanceOf(CityActivity::class, app(Explorer::class)->begin($this->character->fresh()));
    }

    public function test_a_pass_takes_the_base_time_and_is_not_settled_early(): void
    {
        $this->freezeTime();
        $this->die(0.0);
        app(Explorer::class)->begin($this->character->fresh());

        $this->travel(239)->seconds();
        $this->assertSame([], app(CityClock::class)->settle($this->character->fresh()));

        $this->travel(2)->seconds();
        $this->assertCount(1, app(CityClock::class)->settle($this->character->fresh()));
    }

    public function test_cancelling_loses_the_roll(): void
    {
        $this->die(0.0);
        app(Explorer::class)->begin($this->character->fresh());

        $this->assertTrue(app(Explorer::class)->cancel($this->character->fresh()));
        $this->travel(5)->minutes();
        app(CityClock::class)->settle($this->character->fresh());

        $this->assertSame([], $this->found());
    }

    public function test_one_thing_at_a_time_and_not_while_jacked_in(): void
    {
        $explorer = app(Explorer::class);

        $this->assertInstanceOf(CityActivity::class, $explorer->begin($this->character->fresh()));
        $this->assertSame(CityRefusal::Busy, $explorer->begin($this->character->fresh()));

        $this->character->forceFill(['grid_x' => 1, 'grid_y' => 1])->save();
        CityActivity::query()->delete();
        $this->assertSame(CityRefusal::JackedIn, $explorer->begin($this->character->fresh()));
    }

    public function test_exploration_has_no_certainty_floor(): void
    {
        $roller = CheckRoller::fromConfig();

        $this->assertSame(0.0, $roller->floorFor('exploration'));
        $this->assertGreaterThan(0.0, $roller->floorFor('detection') ?: 0.0085);
        $this->assertLessThan(0.0085, $roller->chance(0.0, 51.0, 'exploration'), 'hopeless is below the global floor');
    }

    public function test_the_pool_is_read_through_the_sheet(): void
    {
        // (focus + perception + senses) / 4, all at 20.
        $this->assertEqualsWithDelta(15.0, $this->character->stat('exploration')->value, 0.001);
    }

    public function test_a_found_place_shows_on_the_map_and_hidden_ones_do_not(): void
    {
        $this->die(0.0);
        $this->explore();

        $this->actingAs($this->character->user)->get(route('city.district', 'knightsbridge-projects'))
            ->assertOk()->assertSeeText('The Sub-Basement')->assertDontSeeText('The Stairwell Market');
    }
}
