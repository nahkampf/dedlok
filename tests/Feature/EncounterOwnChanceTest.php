<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\City\Encounters;
use App\City\EncounterStart;
use App\Models\Character;
use App\Models\CharacterEncounter;
use App\Models\Encounter;
use App\Models\User;
use App\Stats\Resolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/** An encounter may set its own chance, overriding its door's default and rolled on its own. */
final class EncounterOwnChanceTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 16);
        Encounter::query()->delete();

        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        $this->equipReadyDeck($this->character);
        $this->character->forceFill(['grid_x' => 8, 'grid_y' => 8])->save();
        $this->character = $this->character->fresh();
    }

    /** Pin every die to one value. */
    private function dice(int $value): void
    {
        $this->app->bind(Encounters::class, fn ($app): Encounters => Encounters::fromConfig(
            $app->make(Resolver::class),
            static fn (int $min, int $max): int => min($max, max($min, $value)),
        ));
    }

    private function make(string $slug, ?float $chance = null): Encounter
    {
        $e = Encounter::create(['slug' => $slug, 'title' => $slug, 'starts_on' => 'grid_step', 'weight' => 100, 'chance' => $chance, 'once' => false, 'retired' => false]);
        $e->nodes()->create(['node_key' => 'only', 'body' => 'x', 'entry' => true, 'ends' => true]);

        return $e;
    }

    private function opened(): ?string
    {
        return app(Encounters::class)->maybeOpen($this->character->fresh(), EncounterStart::GridStep)?->encounter?->slug;
    }

    public function test_an_encounter_with_its_own_chance_opens_though_the_door_would_not(): void
    {
        Config::set('cyberspace.encounter.grid_step_chance', 0.0);
        $this->make('rare_one', 50.0);

        $this->dice(1);
        $this->assertSame('rare_one', $this->opened());
    }

    public function test_its_chance_is_the_odds_not_a_certainty(): void
    {
        Config::set('cyberspace.encounter.grid_step_chance', 0.0);
        $this->make('rare_one', 1.0);

        $this->dice(5_000);   // a roll of 5,000 in 10,000 is past one percent
        $this->assertNull($this->opened());

        $this->dice(100);     // one percent is exactly 100 in 10,000
        $this->assertSame('rare_one', $this->opened());
    }

    public function test_an_encounter_with_its_own_chance_is_left_out_of_the_door_draw(): void
    {
        Config::set('cyberspace.encounter.grid_step_chance', 1.0);
        $this->make('plain');
        $this->make('rare_one', 1.0);

        // Its own roll fails, the door's succeeds, and only the plain one is in that draw.
        $this->dice(5_000);
        $this->assertSame('plain', $this->opened());

        // Close that one, then: its own roll lands first.
        $this->character->fresh()->forceFill(['current_encounter_id' => null])->save();
        CharacterEncounter::query()->delete();
        $this->dice(1);
        $this->assertSame('rare_one', $this->opened());
    }

    public function test_the_editor_saves_it_and_refuses_it_on_a_button(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $e = $this->make('edited');

        $save = fn (string $door, array $extra = []) => $this->actingAs($admin)->put(route('admin.encounters.update', $e), $extra + [
            'title' => 'x', 'starts_on' => $door, 'weight' => 5, 'entry' => 0, 'chance' => '3.5',
            'nodes' => [['key' => 'edited_a', 'body' => 'x', 'ends' => '1']],
        ]);

        $save('grid_step')->assertSessionHasNoErrors();
        $this->assertSame(3.5, (float) $e->fresh()->chance);

        $save('grid_step', ['chance' => ''])->assertSessionHasNoErrors();
        $this->assertNull($e->fresh()->chance, 'blank is the door default');

        $save('spot', ['spot_address' => 'AAAAAAAA'])->assertSessionHasErrors('chance');
        $save('grid_step', ['chance' => '0'])->assertSessionHasErrors('chance');
        $save('grid_step', ['chance' => '101'])->assertSessionHasErrors('chance');
    }
}
