<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\City\CityRefusal;
use App\City\FeatureEditor;
use App\City\Kitchen;
use App\Models\Character;
use App\Models\District;
use App\Models\Location;
use App\Models\User;
use App\Models\WalletEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A place with a menu is a place to eat. FATIGUE.md, *Food and fullness*. */
final class KitchenTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    private Location $diner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3, 'creds' => 100, 'current_fatigue' => 40]));
        $this->diner = Location::create([
            'district_id' => District::startId(), 'slug' => 'noodle-bar', 'name' => 'Noodle Bar', 'map_x' => 50, 'map_y' => 50, 'discovery' => 'open',
        ]);
        FeatureEditor::apply($this->diner, [], [], [], null, [
            ['name' => 'Noodles', 'price' => 12, 'meal_fullness' => 30, 'meal_fatigue' => 6],
        ]);
    }

    public function test_a_place_with_a_menu_is_a_place_to_eat(): void
    {
        $this->assertSame('Food', $this->diner->features()[0]->label());
    }

    public function test_eating_pays_fills_and_eases(): void
    {
        $meal = $this->diner->meals()->firstOrFail();

        $this->assertTrue(app(Kitchen::class)->eat($this->character, $this->diner, $meal->id));

        $fresh = $this->character->fresh();
        $this->assertSame(88, $fresh->creds);
        $this->assertEquals(30, $fresh->current_fullness);
        $this->assertEquals(34, $fresh->current_fatigue);
        $this->assertSame('Noodles at Noodle Bar', WalletEntry::query()->where('character_id', $fresh->id)->latest('id')->value('note'));
    }

    public function test_a_full_stomach_and_an_empty_wallet_refuse(): void
    {
        $meal = $this->diner->meals()->firstOrFail();
        $kitchen = app(Kitchen::class);

        $kitchen->eat($this->character, $this->diner, $meal->id);
        $kitchen->eat($this->character->fresh(), $this->diner, $meal->id);

        $this->assertSame(CityRefusal::TooFull, $kitchen->eat($this->character->fresh(), $this->diner, $meal->id));

        $this->character->forceFill(['creds' => 5, 'current_fullness' => 0])->save();
        $this->assertSame(CityRefusal::CannotAfford, $kitchen->eat($this->character->fresh(), $this->diner, $meal->id));
    }

    public function test_you_must_be_there_and_the_meal_must_be_on_the_menu(): void
    {
        $meal = $this->diner->meals()->firstOrFail();
        $this->assertSame(CityRefusal::NotSold, app(Kitchen::class)->eat($this->character, $this->diner, $meal->id + 99));

        $this->character->forceFill(['district_id' => District::query()->where('slug', 'dockside')->value('id')])->save();
        $this->assertSame(CityRefusal::NotHere, app(Kitchen::class)->eat($this->character->fresh(), $this->diner, $meal->id));
    }

    public function test_the_place_page_and_button_work_and_the_editor_keeps_the_menu(): void
    {
        $meal = $this->diner->meals()->firstOrFail();
        $user = $this->character->user;

        $this->actingAs($user)->get(route('city.place', 'noodle-bar'))->assertOk()->assertSee('Noodles');
        $this->actingAs($user)->post(route('city.eat', ['noodle-bar', $meal->id]))->assertSessionHas('status');

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin)->put(route('admin.locations.update', $this->diner), [
            'district_id' => $this->diner->district_id, 'name' => 'Noodle Bar', 'map_x' => 50, 'map_y' => 50, 'discovery' => 'open',
            'meals' => [['name' => 'Soup', 'price' => 5, 'meal_fullness' => 10, 'meal_fatigue' => 1], ['name' => '']],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['Soup'], $this->diner->meals()->pluck('name')->all());
        $this->actingAs($admin)->get(route('admin.locations.edit', $this->diner))->assertOk()->assertSee('Soup');
    }
}
