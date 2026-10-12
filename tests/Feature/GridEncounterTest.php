<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Characters\Flags;
use App\City\Encounters;
use App\City\EncounterStart;
use App\Cyberspace\Navigator;
use App\Cyberspace\Position;
use App\Cyberspace\TravelResult;
use App\Models\Character;
use App\Models\Encounter;
use App\Stats\Resolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/**
 * An encounter that opens on a move in cyberspace: the grid door, and the authored introduction.
 */
final class GridEncounterTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 16);

        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        // Somebody on the grid must have a deck that runs, or the lock ejects them on the next request.
        $this->equipReadyDeck($this->character);
        $this->character->forceFill(['grid_x' => 8, 'grid_y' => 8])->save();
        $this->character->refresh();
    }

    /** Pin the spawn die: 1 is always inside the chance. */
    private function spawnRolls(int $value): void
    {
        $this->app->bind(Encounters::class, fn ($app): Encounters => Encounters::fromConfig(
            $app->make(Resolver::class),
            static fn (int $min, int $max): int => min($max, max($min, $value)),
        ));
    }

    private function move(): TravelResult
    {
        // The hunters' roll is its own die and a character with no heat has no hunt to roll.
        return Navigator::fromConfig(app(Resolver::class))->step($this->character->fresh(), new Position(9, 8));
    }

    public function test_a_move_can_open_the_introduction_once(): void
    {
        $this->spawnRolls(1);

        $result = $this->move();

        $this->assertTrue($result->metSomething());
        $live = $this->character->fresh();
        $this->assertNotNull($live->current_encounter_id);
        $this->assertTrue($live->isJackedIn(), 'still on the grid');
        $this->assertTrue(Flags::has($live, 'encounter_loa_intro_encounter'), 'once: seen the moment it opens');
    }

    public function test_it_does_not_open_again_for_somebody_who_has_seen_it(): void
    {
        $this->spawnRolls(1);
        Flags::set($this->character, 'encounter_loa_intro_encounter');

        $this->assertFalse($this->move()->metSomething());
    }

    public function test_a_roll_outside_the_chance_opens_nothing(): void
    {
        $this->spawnRolls(10_000);

        $this->assertFalse($this->move()->metSomething());
        $this->assertNull($this->character->fresh()->current_encounter_id);
    }

    public function test_jacking_in_is_not_a_move(): void
    {
        $this->spawnRolls(1);
        $this->character->forceFill(['grid_x' => null, 'grid_y' => null])->save();

        $this->assertNull(app(Encounters::class)->maybeOpen($this->character->fresh(), EncounterStart::Travel), 'a City door never opens it');
        $this->assertFalse(app(Encounters::class)->canHappenTo($this->character->fresh(), EncounterStart::GridStep), 'not on the grid, nothing happens there');
    }

    public function test_either_answer_ends_on_an_ending_that_sets_the_flag_and_returns_to_the_map(): void
    {
        $this->spawnRolls(1);
        $this->assertTrue($this->move()->metSomething());

        $user = $this->character->user;
        $encounter = Encounter::query()->where('slug', 'loa_intro_encounter')->firstOrFail();
        $choice = fn (string $node, string $label) => $encounter->nodes()->where('node_key', $node)->firstOrFail()
            ->choices()->where('label', $label)->firstOrFail()->id;

        // The opening, then a dialogue, then No.
        $this->actingAs($user)->post(route('encounter.choose'), ['choice' => $choice('loa_intro_opening', 'Placeholder')])
            ->assertRedirect(route('encounter.show'));
        $this->actingAs($user)->post(route('encounter.choose'), ['choice' => $choice('loa_intro_dialogue1', 'No')])
            ->assertRedirect(route('encounter.show'));

        $this->assertTrue(Flags::has($this->character->fresh(), 'loa_introduced'), 'set on arrival at the ending');

        $this->actingAs($user)->post(route('encounter.leave'))->assertRedirect(route('cyberspace.index'));
        $this->assertNull($this->character->fresh()->current_encounter_id);
        $this->assertTrue($this->character->fresh()->isJackedIn());
    }
}
