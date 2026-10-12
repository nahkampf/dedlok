<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Characters\Flags;
use App\City\Encounters;
use App\City\EncounterStart;
use App\Models\Character;
use App\Models\CharacterEncounter;
use App\Models\Encounter;
use App\Models\System;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/** The spot door: an encounter pinned to a system, a button in the Interact box, not rolled. */
final class GridSpotTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    private Character $character;

    private System $system;

    private Encounter $spot;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 16);
        Encounter::query()->delete();

        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        $this->equipReadyDeck($this->character);
        $this->character->forceFill(['grid_x' => 8, 'grid_y' => 8])->save();
        $this->character = $this->character->fresh();

        $this->system = System::factory()->at(8, 8)->create(['address' => 'AAAAAAAA']);
        $this->spot = Encounter::create([
            'slug' => 'a_spot', 'title' => 'Talk to the clerk', 'starts_on' => 'spot', 'weight' => 1,
            'spot_system_id' => $this->system->id, 'cooldown_hours' => 6, 'once' => false, 'retired' => false,
        ]);
        $this->spot->nodes()->create(['node_key' => 'only', 'body' => 'x', 'entry' => true, 'ends' => true]);
    }

    private function offered(): bool
    {
        return app(Encounters::class)->spotsAt($this->character->fresh(), $this->system->fresh())->contains('id', $this->spot->id);
    }

    public function test_it_is_offered_at_its_system_and_the_button_is_on_the_grid_screen(): void
    {
        $this->assertTrue($this->offered());

        $this->actingAs($this->character->user)->get(route('cyberspace.index'))
            ->assertOk()
            ->assertSee('Talk to the clerk')
            ->assertSee(route('cyberspace.spot', $this->spot->id), false);
    }

    public function test_pressing_it_opens_it_without_a_roll_and_starts_the_cooldown(): void
    {
        $this->actingAs($this->character->user)->post(route('cyberspace.spot', $this->spot->id))->assertRedirect(route('encounter.show'));

        $this->assertNotNull($this->character->fresh()->current_encounter_id);

        // Closed, it is on cooldown for six hours.
        $this->character->fresh()->forceFill(['current_encounter_id' => null])->save();
        CharacterEncounter::query()->delete();
        $this->assertFalse($this->offered());

        $this->travel(7)->hours();
        $this->assertTrue($this->offered());
    }

    public function test_it_is_not_offered_at_another_system_or_without_its_conditions(): void
    {
        $other = System::factory()->at(9, 9)->create(['address' => 'BBBBBBBB']);
        $this->assertFalse(app(Encounters::class)->spotsAt($this->character->fresh(), $other)->contains('id', $this->spot->id));

        $this->spot->prerequisites()->create(['kind' => 'flag', 'subject' => 'know_the_clerk', 'position' => 0, 'negate' => false]);
        $this->assertFalse($this->offered());

        Flags::set($this->character, 'know_the_clerk');
        $this->assertTrue($this->offered());
    }

    public function test_it_follows_its_machine_when_it_relocates_and_goes_dormant_when_burned(): void
    {
        $this->system->update(['x' => 3, 'y' => 3, 'address' => 'CCCCCCCC']);
        $this->character->forceFill(['grid_x' => 3, 'grid_y' => 3])->save();

        $this->assertTrue($this->offered(), 'the same machine, a new address');

        $this->system->delete();
        $this->assertSame(0, app(Encounters::class)->eligible($this->character->fresh(), EncounterStart::Spot)->filter(
            fn (Encounter $e): bool => System::query()->whereKey($e->spot_system_id)->exists(),
        )->count());
        $this->assertNotNull(Encounter::query()->find($this->spot->id), 'authored content is not deleted with the machine');
    }

    public function test_the_editor_pins_it_by_address(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        $this->actingAs($admin)->put(route('admin.encounters.update', $this->spot), [
            'title' => 'Talk to the clerk', 'starts_on' => 'spot', 'weight' => 1, 'entry' => 0,
            'spot_address' => 'AAAAAAAA', 'cooldown_hours' => 12,
            'nodes' => [['key' => 'a_spot_a', 'body' => 'x', 'ends' => '1']],
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->system->id, (int) $this->spot->fresh()->spot_system_id);
        $this->assertSame(12, (int) $this->spot->fresh()->cooldown_hours);

        $this->actingAs($admin)->put(route('admin.encounters.update', $this->spot), [
            'title' => 'x', 'starts_on' => 'spot', 'weight' => 1, 'entry' => 0, 'spot_address' => 'ZZZZZZZZ',
            'nodes' => [['key' => 'a_spot_a', 'body' => 'x', 'ends' => '1']],
        ])->assertSessionHasErrors('spot_address');
    }
}
