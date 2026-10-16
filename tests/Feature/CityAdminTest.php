<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Character;
use App\Models\District;
use App\Models\Item;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CityAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_admin' => true])->save();
    }

    private function district(array $over = []): array
    {
        return array_merge([
            'slug' => 'the-sprawl', 'name' => 'The Sprawl', 'color' => 'moss',
            'map_x' => 40, 'map_y' => 50, 'travel_weight' => 1.5,
        ], $over);
    }

    public function test_only_an_admin_gets_in(): void
    {
        $player = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 10), ['archetype' => 3]));

        $this->actingAs($player->user)->get(route('admin.districts.index'))->assertForbidden();
    }

    public function test_a_district_is_created_with_its_links_stored_lower_id_first(): void
    {
        $oldTown = District::query()->where('slug', 'old-town')->firstOrFail();

        $this->actingAs($this->admin)->post(route('admin.districts.store'), $this->district(['links' => [$oldTown->id]]))->assertRedirect();

        $new = District::query()->where('slug', 'the-sprawl')->firstOrFail();
        $this->assertSame(1, DB::table('district_links')->where('district_a_id', min($new->id, $oldTown->id))->where('district_b_id', max($new->id, $oldTown->id))->count());
    }

    public function test_coordinates_are_kept_inside_the_audited_range(): void
    {
        $this->actingAs($this->admin)->post(route('admin.districts.store'), $this->district(['map_x' => 95]))->assertSessionHasErrors('map_x');
        $this->actingAs($this->admin)->post(route('admin.locations.store'), [
            'district_id' => District::query()->value('id'), 'slug' => 'x', 'name' => 'X', 'map_x' => 10, 'map_y' => 50, 'discovery' => 'open',
        ])->assertSessionHasErrors('map_x');
    }

    public function test_a_slug_is_permanent_and_a_district_cannot_link_to_itself(): void
    {
        $district = District::query()->where('slug', 'dockside')->firstOrFail();

        $this->actingAs($this->admin)->put(route('admin.districts.update', $district), $this->district(['slug' => 'renamed']))->assertSessionHasErrors('slug');
        $this->actingAs($this->admin)->put(route('admin.districts.update', $district), ['name' => 'Dockside', 'color' => 'cyan', 'map_x' => 22, 'map_y' => 70, 'travel_weight' => 1, 'links' => [$district->id]])->assertSessionHasErrors('links.0');
    }

    /**
     * `prohibited` lets an EMPTY value through, so a hand-built update with a blank slug used to
     * pass validation and then try to write NULL into a NOT NULL column: a 500. A slug is
     * permanent, and the request must not even look at what it was sent.
     */
    public function test_dragged_pins_are_saved_clamped_and_only_for_this_district(): void
    {
        $location = Location::query()->firstOrFail();
        $other = Location::query()->where('district_id', '!=', $location->district_id)->firstOrFail();
        $before = [$other->map_x, $other->map_y];
        $district = District::query()->findOrFail($location->district_id);

        $this->actingAs($this->admin)->put(route('admin.districts.update', $district), [
            'name' => $district->name, 'color' => $district->color->value, 'map_x' => $district->map_x,
            'map_y' => $district->map_y, 'travel_weight' => $district->travel_weight,
            'positions' => json_encode([$location->id => [30.4, 99], $other->id => [60, 60]]),
        ])->assertSessionHasNoErrors();

        $this->assertSame([30, 75], [$location->fresh()->map_x, $location->fresh()->map_y]);
        $this->assertSame($before, [$other->fresh()->map_x, $other->fresh()->map_y]);
    }

    public function test_a_blank_slug_on_update_is_ignored_not_written(): void
    {
        $location = Location::query()->firstOrFail();
        $slug = $location->slug;

        $this->actingAs($this->admin)->put(route('admin.locations.update', $location), [
            'slug' => null, 'district_id' => $location->district_id, 'name' => 'Renamed',
            'map_x' => 50, 'map_y' => 50, 'discovery' => 'open',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame($slug, $location->fresh()->slug);
        $this->assertSame('Renamed', $location->fresh()->name);
    }

    public function test_a_blank_slug_on_a_district_update_is_ignored_not_written(): void
    {
        $district = District::query()->where('slug', 'dockside')->firstOrFail();

        $this->actingAs($this->admin)->put(route('admin.districts.update', $district), $this->district(['slug' => null, 'name' => 'Docks']))
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('dockside', $district->fresh()->slug);
        $this->assertSame('Docks', $district->fresh()->name);
    }

    public function test_the_discovery_rule_decides_which_field_is_needed(): void
    {
        $base = ['district_id' => District::query()->value('id'), 'slug' => 'a-place', 'name' => 'A place', 'map_x' => 50, 'map_y' => 50];
        $post = fn (array $extra) => $this->actingAs($this->admin)->post(route('admin.locations.store'), $base + $extra);

        $post(['discovery' => 'explorable'])->assertSessionHasErrors('concealment');
        $post(['discovery' => 'explored_by_flag'])->assertSessionHasErrors('required_flag');
        $post(['discovery' => 'open', 'concealment' => 'trivial'])->assertSessionHasErrors('concealment');
        $post(['discovery' => 'explorable', 'concealment' => 'obscure'])->assertSessionHasNoErrors();

        $this->assertSame('obscure', Location::query()->where('slug', 'a-place')->firstOrFail()->concealment->value);
    }

    public function test_a_district_with_somebody_in_it_or_the_start_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin)->delete(route('admin.districts.destroy', District::query()->where('slug', 'old-town')->firstOrFail()))
            ->assertSessionHas('error');
        $this->assertSame(7, District::query()->count());

        $empty = District::query()->where('slug', 'little-moscow')->firstOrFail();
        $this->actingAs($this->admin)->delete(route('admin.districts.destroy', $empty))->assertSessionHas('status');
        $this->assertNull(District::query()->where('slug', 'little-moscow')->first());

        $occupied = District::query()->where('slug', 'eastside')->firstOrFail();
        $c = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 10), ['archetype' => 3]));
        $c->forceFill(['district_id' => $occupied->id])->save();
        $this->actingAs($this->admin)->delete(route('admin.districts.destroy', $occupied))->assertSessionHas('error');
        $this->assertNotNull(District::query()->where('slug', 'eastside')->first());
    }

    public function test_the_editor_screens_render(): void
    {
        $district = District::query()->firstOrFail();
        $location = Location::query()->firstOrFail();

        foreach ([
            route('admin.districts.index'), route('admin.districts.create'), route('admin.districts.edit', $district),
            route('admin.locations.index'), route('admin.locations.create'), route('admin.locations.edit', $location),
            route('admin.index'),
        ] as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk();
        }
    }

    public function test_the_shop_picker_is_a_card_select_with_cost_shelf_and_rarity(): void
    {
        $item = Item::factory()->physical()->create(['name' => 'Chrome Thing', 'base_cost' => 1234, 'rarity' => 'rare']);
        DB::table('market_stock')->insert(['item_id' => $item->id, 'quantity' => 7, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->admin)
            ->get(route('admin.locations.edit', Location::query()->firstOrFail()))
            ->assertOk()
            ->assertSee('select--cards', false)
            ->assertSee('Chrome Thing')
            ->assertSee('Physical · 1,234 ¤ · market shelf 7 · Rare', false);
    }
}
