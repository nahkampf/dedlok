<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\City\Traveller;
use App\City\TravelMode;
use App\Models\Character;
use App\Models\CityActivity;
use App\Models\District;
use App\Models\Safehouse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The safehouse foundation: the types, the editor, the fallback, and the free move. docs/FATIGUE.md, *Safehouses*.
 */
final class SafehouseTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_admin' => true])->save();
    }

    private function character(): Character
    {
        return Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'slug' => 'capsule', 'title' => 'Capsule hotel', 'district_id' => District::query()->where('slug', 'dockside')->value('id'),
            'rent' => 40, 'security' => 10, 'stealth' => 5, 'rest_rate' => 2.5,
        ], $over);
    }

    public function test_the_fallback_is_a_row_in_knightsbridge_projects(): void
    {
        $box = Safehouse::fallback();

        $this->assertSame('cardboard-box', $box->slug);
        $this->assertSame('knightsbridge-projects', $box->district->slug);
        $this->assertSame(0, $box->rent);
    }

    public function test_a_character_with_no_safehouse_lives_in_the_fallback(): void
    {
        $character = $this->character();

        $this->assertNull($character->safehouse_id);
        $this->assertSame(Safehouse::fallback()->id, $character->home()->id);
    }

    public function test_a_character_lives_in_the_one_they_hold_and_it_names_one_district(): void
    {
        $capsule = Safehouse::create($this->payload());
        $character = $this->character();
        $character->forceFill(['safehouse_id' => $capsule->id])->save();

        $this->assertSame('dockside', $character->fresh()->home()->district->slug);
    }

    public function test_relocating_is_a_free_plain_move_that_ends_the_activity(): void
    {
        $character = $this->character();
        $character->forceFill(['creds' => 50])->save();
        $to = District::query()->where('slug', 'downtown')->firstOrFail();

        app(Traveller::class)->begin($character, $to, TravelMode::Pod);
        $creds = $character->fresh()->creds;

        app(Traveller::class)->relocate($character->fresh(), Safehouse::fallback()->district);

        $this->assertSame('knightsbridge-projects', District::query()->find($character->fresh()->district_id)->slug);
        $this->assertSame($creds, $character->fresh()->creds, 'no fare, no refund');
        $this->assertNull(CityActivity::query()->find($character->id));
    }

    public function test_only_an_admin_gets_in(): void
    {
        $this->actingAs($this->character()->user)->get(route('admin.safehouses.index'))->assertForbidden();
    }

    public function test_a_safehouse_is_created_and_its_slug_is_permanent(): void
    {
        $this->actingAs($this->admin)->post(route('admin.safehouses.store'), $this->payload())->assertRedirect();

        $capsule = Safehouse::query()->where('slug', 'capsule')->firstOrFail();
        $this->assertSame(2.5, $capsule->rest_rate);

        $this->actingAs($this->admin)->put(route('admin.safehouses.update', $capsule), $this->payload(['slug' => 'renamed']))->assertSessionHasErrors('slug');
        $this->actingAs($this->admin)->put(route('admin.safehouses.update', $capsule), array_diff_key($this->payload(['rent' => 60]), ['slug' => 1]))->assertSessionHasNoErrors();
        $this->assertSame(60, $capsule->fresh()->rent);
    }

    public function test_the_fallback_cannot_be_deleted_but_any_other_can_and_sends_its_holders_home(): void
    {
        $this->actingAs($this->admin)->delete(route('admin.safehouses.destroy', Safehouse::fallback()))->assertSessionHas('error');
        $this->assertNotNull(Safehouse::query()->where('slug', 'cardboard-box')->first());

        $capsule = Safehouse::create($this->payload());
        $character = $this->character();
        $character->forceFill(['safehouse_id' => $capsule->id])->save();

        $this->actingAs($this->admin)->delete(route('admin.safehouses.destroy', $capsule))->assertRedirect();

        $this->assertNull($character->fresh()->safehouse_id);
        $this->assertSame('cardboard-box', $character->fresh()->home()->slug);
    }

    public function test_a_district_holding_a_safehouse_cannot_be_deleted(): void
    {
        $district = Safehouse::fallback()->district;

        $this->actingAs($this->admin)->delete(route('admin.districts.destroy', $district))->assertSessionHas('error');
        $this->assertNotNull(District::query()->find($district->id));
    }

    public function test_the_editor_pages_render(): void
    {
        $this->actingAs($this->admin)->get(route('admin.safehouses.index'))->assertOk()->assertSee('Cardboard box under the bridge');
        $this->actingAs($this->admin)->get(route('admin.safehouses.create'))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.safehouses.edit', Safehouse::fallback()))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.index'))->assertOk();
    }
}
