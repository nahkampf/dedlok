<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Characters\Flags;
use App\City\Discovery;
use App\Http\Middleware\EnsureDeckStillRuns;
use App\Models\Character;
use App\Models\District;
use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

final class CityTest extends TestCase
{
    use RefreshDatabase;

    private function character(): Character
    {
        return Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 10),
            ['archetype' => 3],
        ));
    }

    private function slugs(Character $c, string $district): array
    {
        return Location::query()
            ->whereHas('district', fn ($q) => $q->where('slug', $district))
            ->visibleTo($c)
            ->pluck('slug')->all();
    }

    public function test_the_starter_city_has_seven_districts_and_a_start(): void
    {
        $this->assertSame(7, District::query()->count());
        $this->assertNotNull(District::startId());
        $this->assertSame('Old Town', District::query()->findOrFail(District::startId())->name);
    }

    public function test_every_district_has_a_colour_and_the_map_draws_it(): void
    {
        $this->assertSame(7, District::query()->pluck('color')->unique()->count(), 'seven districts, seven colours');

        $c = $this->character();
        $this->actingAs($c->user)->get(route('city.index'))
            ->assertOk()->assertSee('--hue:var(--violet)', false);
    }

    public function test_every_character_starts_in_the_starting_district(): void
    {
        $this->assertSame(District::startId(), $this->character()->district_id);
    }

    public function test_only_open_locations_are_visible_at_first(): void
    {
        $c = $this->character();

        $this->assertSame(['the-rusty-nail'], $this->slugs($c, 'old-town'));
        $this->assertSame([], $this->slugs($c, 'knightsbridge-projects'));
    }

    public function test_exploring_a_location_makes_it_visible_once(): void
    {
        $c = $this->character();
        $clinic = Location::query()->where('slug', 'back-alley-clinic')->firstOrFail();

        $this->assertTrue(Discovery::unlock($c, $clinic));
        $this->assertFalse(Discovery::unlock($c, $clinic), 'not news the second time');
        $this->assertContains('back-alley-clinic', $this->slugs($c, 'old-town'));
        $this->assertNotContains('back-alley-clinic', $this->slugs($this->character(), 'old-town'), 'per character');
    }

    public function test_only_an_explorable_location_is_ever_found(): void
    {
        $c = $this->character();
        $open = Location::query()->where('slug', 'the-rusty-nail')->firstOrFail();

        $this->assertFalse(Discovery::unlock($c, $open));
        $this->assertSame(0, DB::table('character_locations')->count());
    }

    public function test_a_flag_makes_its_location_visible_with_no_unlock_step(): void
    {
        $c = $this->character();

        $this->assertSame([], $this->slugs($c, 'crystal-heights'));

        $this->assertTrue(Flags::set($c, 'crystal_heights_invite'));
        $this->assertFalse(Flags::set($c, 'crystal_heights_invite'));
        $this->assertSame(['skybridge-lounge'], $this->slugs($c, 'crystal-heights'));
        $this->assertSame(0, DB::table('character_locations')->count());
    }

    public function test_a_flag_must_be_a_slug(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Flags::set($this->character(), 'Not A Slug');
    }

    public function test_the_flag_command_sets_one(): void
    {
        $c = $this->character();

        $this->artisan('flag:set', ['handle' => $c->handle, 'flag' => 'a_flag'])->assertSuccessful();
        $this->assertTrue(Flags::has($c, 'a_flag'));
    }

    public function test_the_maps_render_and_never_show_what_is_hidden(): void
    {
        $c = $this->character();

        $this->actingAs($c->user)->get(route('city.index'))
            ->assertOk()->assertSeeText('Knightsbridge Projects');

        $this->actingAs($c->user)->get(route('city.district', 'old-town'))
            ->assertOk()->assertSeeText('The Rusty Nail')->assertDontSeeText('Back-Alley Clinic');
    }

    public function test_the_city_refuses_somebody_who_is_jacked_in(): void
    {
        $c = $this->character();
        $c->forceFill(['grid_x' => 1, 'grid_y' => 1])->save();

        // Without the readiness net: this character has no deck, and the net
        // would throw them out before the City ever saw them.
        $this->withoutMiddleware(EnsureDeckStillRuns::class)
            ->actingAs($c->user)->get(route('city.index'))
            ->assertRedirect(route('cyberspace.index'));
    }
}
