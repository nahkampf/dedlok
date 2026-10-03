<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Characters\WaitingRoom;
use App\Cyberspace\Navigator;
use App\Cyberspace\ReadinessWatch;
use App\Models\Character;
use App\Models\CharacterModifier;
use App\Models\Item;
use App\Programs\ProgramKind;
use App\Stats\Resolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/**
 * A deck that stops running throws its owner out of cyberspace.
 *
 * The other half of the readiness gate: `isReady()` is asked on the way in,
 * and it has to keep being true while somebody is standing inside. The deck
 * itself is locked, so the only thing that can break it is the resolver —
 * which is why every case here breaks it with a MODIFIER and never by
 * touching the machine.
 *
 * Power is the measure these tests use, because it is the only requirement a
 * modifier can currently reach: compute, memory and link are counts of fitted
 * modules, and the budget is the one verdict that depends on numbers the
 * resolver can move.
 */
final class ReadinessWatchTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 16);
        Config::set('cyberspace.view_range', 2);

        $this->character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['archetype' => 3],
        ));
    }

    private function jackIn(): void
    {
        $result = Navigator::fromConfig(app(Resolver::class))->jackIn($this->character);

        $this->assertTrue($result->succeeded(), 'the fixture has to get in before it can be thrown out');
        $this->character->refresh();
    }

    /**
     * Cut the power, from outside the machine.
     *
     * An effect definition that overrides the deck's supply to nothing, so a
     * deck drawing 40 W is suddenly over budget. This is the shape the first
     * real cause will have — a drug wearing off, an injury landing — and it is
     * the only way to break a deck somebody is standing in, since the deck
     * itself is locked.
     */
    private function cutThePower(): CharacterModifier
    {
        Config::set('effects.brownout', [
            'label' => 'Brownout',
            'stacking' => 'unique',
            'retired' => false,
            'modifiers' => [
                ['op' => 'override', 'amount' => 0, 'target' => ['id' => 'deck_power_supply']],
            ],
        ]);

        return CharacterModifier::query()->create([
            'character_id' => $this->character->id,
            'definition_key' => 'brownout',
        ]);
    }

    private function watch(): ReadinessWatch
    {
        return ReadinessWatch::fromConfig(app(Resolver::class));
    }

    private function synapticIntegrity(): int
    {
        return (int) $this->character->fresh()->current_synaptic_integrity;
    }

    // ---- when nothing is wrong --------------------------------------------

    public function test_a_deck_that_still_runs_is_left_alone(): void
    {
        $this->equipReadyDeck($this->character);
        $this->jackIn();
        $before = $this->synapticIntegrity();

        $this->assertNull($this->watch()->check($this->character));
        $this->assertTrue($this->character->fresh()->isJackedIn());
        $this->assertSame($before, $this->synapticIntegrity());
    }

    /** Out of cyberspace, and therefore not this class's business. */
    public function test_a_character_who_is_not_inside_is_never_ejected(): void
    {
        $this->equipReadyDeck($this->character);
        $this->cutThePower();
        $before = $this->synapticIntegrity();

        $this->assertNull($this->watch()->check($this->character));
        $this->assertSame($before, $this->synapticIntegrity());
    }

    // ---- when the machine stops -------------------------------------------

    public function test_a_deck_that_stops_running_throws_its_owner_out(): void
    {
        $this->equipReadyDeck($this->character);
        $this->jackIn();
        $before = $this->synapticIntegrity();

        $this->cutThePower();

        $ejection = $this->watch()->check($this->character);

        $this->assertNotNull($ejection);
        $this->assertSame(1, $ejection->damage);
        $this->assertFalse($this->character->fresh()->isJackedIn());
        $this->assertSame($before - 1, $this->synapticIntegrity());
    }

    /** The cost is a config number, not a literal in the class. */
    public function test_what_it_costs_is_configurable(): void
    {
        Config::set('cyberspace.readiness.damage', 3);

        $this->equipReadyDeck($this->character);
        $this->jackIn();
        $before = $this->synapticIntegrity();
        $this->cutThePower();

        $this->assertSame(3, $this->watch()->check($this->character)?->damage);
        $this->assertSame($before - 3, $this->synapticIntegrity());
    }

    /**
     * A character already on nothing still gets thrown out — the ejection is
     * not something the damage pays for.
     */
    public function test_a_character_on_no_integrity_is_still_ejected(): void
    {
        $this->equipReadyDeck($this->character);
        $this->jackIn();
        $this->character->forceFill(['current_synaptic_integrity' => 0])->save();

        $this->cutThePower();

        $ejection = $this->watch()->check($this->character);

        $this->assertNotNull($ejection);
        $this->assertSame(0, $ejection->damage, 'nothing left to take');
        $this->assertFalse($this->character->fresh()->isJackedIn());
        $this->assertSame(0, $this->synapticIntegrity());
    }

    /**
     * The dump is DAMAGE, and damage is resolved. A character who is good at
     * surviving a deck failing under them takes less of it, and none of that
     * is known to this class — it is a modifier against a tag set.
     */
    public function test_what_it_costs_is_mitigated_like_any_other_damage(): void
    {
        Config::set('cyberspace.readiness.damage', 4);
        Config::set('effects.braced', [
            'label' => 'Braced',
            'stacking' => 'unique',
            'retired' => false,
            'modifiers' => [[
                'op' => 'percentage_of_base',
                'amount' => -50,
                'target' => ['all_of' => ['damage', 'readiness_loss']],
            ]],
        ]);

        $this->equipReadyDeck($this->character);
        $this->jackIn();
        $before = $this->synapticIntegrity();

        CharacterModifier::query()->create([
            'character_id' => $this->character->id,
            'definition_key' => 'braced',
        ]);
        $this->cutThePower();

        $this->assertSame(2, $this->watch()->check($this->character)?->damage);
        $this->assertSame($before - 2, $this->synapticIntegrity());
    }

    /**
     * And being good enough at it to take none does not buy off the ejection.
     * The consequence is never paid for with the damage.
     */
    public function test_taking_no_damage_still_ends_the_session(): void
    {
        Config::set('effects.insulated', [
            'label' => 'Insulated',
            'stacking' => 'unique',
            'retired' => false,
            'modifiers' => [[
                'op' => 'multiplier',
                'amount' => 0,
                'target' => ['all_of' => ['damage', 'readiness_loss']],
            ]],
        ]);

        $this->equipReadyDeck($this->character);
        $this->jackIn();
        $before = $this->synapticIntegrity();

        CharacterModifier::query()->create([
            'character_id' => $this->character->id,
            'definition_key' => 'insulated',
        ]);
        $this->cutThePower();

        $ejection = $this->watch()->check($this->character);

        $this->assertNotNull($ejection);
        $this->assertSame(0, $ejection->damage);
        $this->assertFalse($this->character->fresh()->isJackedIn(), 'out is out');
        $this->assertSame($before, $this->synapticIntegrity());
    }

    /** Asking twice must not cost twice. */
    public function test_a_second_look_finds_nobody_standing_there(): void
    {
        $this->equipReadyDeck($this->character);
        $this->jackIn();
        $this->cutThePower();

        $this->watch()->check($this->character);
        $after = $this->synapticIntegrity();

        $this->assertNull($this->watch()->check($this->character->fresh()));
        $this->assertSame($after, $this->synapticIntegrity());
    }

    // ---- the net -----------------------------------------------------------

    public function test_the_middleware_ejects_on_an_ordinary_page(): void
    {
        $this->equipReadyDeck($this->character);
        $this->jackIn();
        $this->cutThePower();

        $this->actingAs($this->character->user)
            ->get(route('skills.index'))
            ->assertOk()
            ->assertSessionHas('error');

        $this->assertFalse($this->character->fresh()->isJackedIn());
    }

    /**
     * A cyberspace request is turned away instead, because the controller's
     * own *you are not in cyberspace* would be true, useless, and would
     * replace the message that explains why.
     */
    public function test_a_cyberspace_request_is_sent_to_the_map(): void
    {
        $this->equipReadyDeck($this->character);
        $this->jackIn();
        $this->cutThePower();

        $this->actingAs($this->character->user)
            ->post(route('cyberspace.step'), ['to' => '8,7'])
            ->assertRedirect(route('cyberspace.index'))
            ->assertSessionHas('error');

        $this->assertFalse($this->character->fresh()->isJackedIn());
    }

    /** And the map itself then loads, rather than bouncing forever. */
    public function test_the_map_loads_once_they_are_out(): void
    {
        $this->equipReadyDeck($this->character);
        $this->jackIn();
        $this->cutThePower();

        $this->actingAs($this->character->user)->get(route('cyberspace.index'))->assertRedirect();
        $this->actingAs($this->character->fresh()->user)->get(route('cyberspace.index'))->assertOk();
    }

    /** Nobody inside, nothing to do: the net costs an unready deck nothing. */
    public function test_the_middleware_leaves_a_jacked_out_player_alone(): void
    {
        $this->equipReadyDeck($this->character);
        $this->cutThePower();

        $this->actingAs($this->character->user)
            ->get(route('skills.index'))
            ->assertOk()
            ->assertSessionMissing('error');
    }

    // ---- in place, where it happens ---------------------------------------

    /**
     * The same for software. A program's modifiers reach the character, and
     * the character is who a deck's numbers are resolved for — so starting one
     * can take the machine it is running on below what it needs.
     */
    public function test_starting_a_program_that_kills_the_deck_says_so_at_once(): void
    {
        $this->equipReadyDeck($this->character);
        $this->jackIn();

        $program = $this->ownedBy($this->character, Item::factory()->program(
            ProgramKind::Daemon,
            background: true,
            modifiers: [
                ['op' => 'override', 'amount' => 0, 'target' => ['id' => 'deck_power_supply']],
            ],
        )->create());

        $this->actingAs($this->character->user)
            ->from(route('inventory.index'))
            ->post(route('inventory.run', $program))
            ->assertRedirect(route('inventory.index'))
            ->assertSessionHas('status')
            ->assertSessionHas('error');

        $this->assertFalse($this->character->fresh()->isJackedIn());
    }

    /**
     * ONE POINT CAN FINISH SOMEBODY, and the order it happens in is the order
     * every bad ending follows: damage, consequence, ejection, and only then
     * the waiting screen.
     *
     * A character must never be sitting on that screen while still holding a
     * position in cyberspace — CYBERSPACE.md calls that the one ordering that
     * produces a state nothing else knows how to read — so this asserts the
     * ejection happened as well as the fall.
     */
    public function test_a_readiness_loss_can_put_somebody_on_the_floor(): void
    {
        $this->equipReadyDeck($this->character);
        $this->jackIn();
        $this->character->forceFill(['current_synaptic_integrity' => 1])->save();
        $this->cutThePower();

        $ejection = $this->watch()->check($this->character->fresh());

        $this->assertNotNull($ejection);

        $this->character->refresh();

        $this->assertSame(0, $this->character->current_synaptic_integrity);
        $this->assertFalse($this->character->isJackedIn(), 'ejected BEFORE the screen, never after');
        $this->assertTrue(app(WaitingRoom::class)->isDown($this->character), 'and then put down');
        $this->assertSame('readiness', $this->character->waiting_cause['reason']);
    }

    /** A hit that does not empty somebody leaves them on their feet. */
    public function test_surviving_a_readiness_loss_does_not_put_anybody_down(): void
    {
        $this->equipReadyDeck($this->character);
        $this->jackIn();
        $this->character->forceFill(['current_synaptic_integrity' => 8])->save();
        $this->cutThePower();

        $this->watch()->check($this->character->fresh());

        $this->assertFalse(app(WaitingRoom::class)->isDown($this->character->fresh()));
        $this->assertNull($this->character->fresh()->waiting_until);
    }
}
