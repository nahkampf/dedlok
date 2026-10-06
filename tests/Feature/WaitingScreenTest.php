<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Characters\DownedCause;
use App\Characters\WaitingRoom;
use App\Models\Character;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The floor under every defeat.
 *
 * The wait is NOT the punishment — what losing costs is whatever the defeat
 * tags carried — so most of what matters here is that it is small, that it
 * ends by itself, and that getting up is not healing.
 */
final class WaitingScreenTest extends TestCase
{
    use RefreshDatabase;

    private function character(): Character
    {
        return Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['archetype' => 3],
        ));
    }

    private function room(): WaitingRoom
    {
        return app(WaitingRoom::class);
    }

    private function cause(): DownedCause
    {
        return new DownedCause(
            reason: 'defeat',
            address: 'DEADBEEF',
            system: 'Data silo - Anansi 041',
            ice: 'ICE VII',
            damage: 12,
            resource: 'current_synaptic_integrity',
            tags: ['flatline', 'skim'],
        );
    }

    public function test_emptying_a_resource_puts_somebody_down(): void
    {
        $character = $this->character();
        $character->forceFill(['current_synaptic_integrity' => 0])->save();

        $this->assertTrue($this->room()->checkFloor($character, $this->cause()));
        $this->assertTrue($this->room()->isDown($character->fresh()));
    }

    public function test_somebody_still_standing_is_left_alone(): void
    {
        $character = $this->character();

        $this->assertFalse($this->room()->checkFloor($character, $this->cause()));
        $this->assertFalse($this->room()->isDown($character->fresh()));
    }

    /** A second hit on an unconscious character is not a second wait. */
    public function test_being_hit_again_does_not_restart_the_clock(): void
    {
        $character = $this->character();
        $character->forceFill(['current_synaptic_integrity' => 0])->save();

        $this->room()->put($character, $this->cause());
        $first = $character->fresh()->waiting_until;

        $this->travel(30)->seconds();
        $this->room()->put($character->fresh(), $this->cause());

        $this->assertEquals($first, $character->fresh()->waiting_until);
    }

    // ---- getting up --------------------------------------------------------

    /**
     * ZERO TO ONE, AND NO FURTHER. Getting up is not healing — it is getting
     * up. One point is enough to act and not enough to be safe.
     */
    public function test_getting_up_returns_a_single_point(): void
    {
        $character = $this->character();
        $character->forceFill(['current_synaptic_integrity' => 0])->save();
        $this->room()->put($character, $this->cause());

        $this->travel(2)->minutes();

        $this->assertTrue($this->room()->release($character->fresh()));

        $character->refresh();
        $this->assertSame(1, $character->current_synaptic_integrity);
        $this->assertNull($character->waiting_until);
        $this->assertNull($character->waiting_cause);
    }

    /** Both halves, or waking still broken would put somebody straight back down. */
    public function test_both_resources_come_back_when_both_were_empty(): void
    {
        $character = $this->character();
        $character->forceFill([
            'current_synaptic_integrity' => 0,
            'current_hitpoints' => 0,
        ])->save();

        $this->room()->put($character, $this->cause());
        $this->travel(2)->minutes();
        $this->room()->release($character->fresh());

        $character->refresh();
        $this->assertSame(1, $character->current_synaptic_integrity);
        $this->assertSame(1, $character->current_hitpoints);
    }

    /** A resource that was NOT empty is not touched: this is not healing. */
    public function test_a_resource_that_was_not_empty_is_left_where_it_was(): void
    {
        $character = $this->character();
        $character->forceFill([
            'current_synaptic_integrity' => 0,
            'current_hitpoints' => 9,
        ])->save();

        $this->room()->put($character, $this->cause());
        $this->travel(2)->minutes();
        $this->room()->release($character->fresh());

        $this->assertSame(9, $character->fresh()->current_hitpoints);
    }

    public function test_the_minute_is_not_over_early(): void
    {
        $character = $this->character();
        $character->forceFill(['current_synaptic_integrity' => 0])->save();
        $this->room()->put($character, $this->cause());

        $this->travel(30)->seconds();

        $this->assertFalse($this->room()->release($character->fresh()));
        $this->assertSame(0, $character->fresh()->current_synaptic_integrity);
    }

    // ---- the lock ----------------------------------------------------------

    public function test_being_down_sends_every_request_to_the_screen(): void
    {
        $character = $this->character();
        $character->forceFill(['current_synaptic_integrity' => 0])->save();
        $this->room()->put($character, $this->cause());

        $this->actingAs($character->user)->get(route('home'))->assertRedirect(route('waiting.show'));
        $this->actingAs($character->user)->get(route('deck.index'))->assertRedirect(route('waiting.show'));
    }

    /** RELEASED ON THE WAY PAST: the minute ends when they look, with no scheduler. */
    public function test_the_wait_ends_on_the_next_request(): void
    {
        $character = $this->character();
        $character->forceFill(['current_synaptic_integrity' => 0])->save();
        $this->room()->put($character, $this->cause());

        $this->travel(2)->minutes();

        $this->actingAs($character->user)->get(route('home'))->assertOk();
        $this->assertSame(1, $character->fresh()->current_synaptic_integrity);
    }

    public function test_the_screen_says_what_happened(): void
    {
        $character = $this->character();
        $character->forceFill(['current_synaptic_integrity' => 0])->save();
        $this->room()->put($character, $this->cause());

        $this->actingAs($character->user)
            ->get(route('waiting.show'))
            ->assertOk()
            ->assertSee('Data silo - Anansi 041')
            ->assertSee('ICE VII')
            ->assertSee('DEADBEEF')
            ->assertSee('flatline');
    }

    public function test_the_box_is_headed_incapacitated_and_wears_the_hazard_stripe(): void
    {
        $character = $this->character();
        $character->forceFill(['current_synaptic_integrity' => 0])->save();
        $this->room()->put($character, $this->cause());

        $this->actingAs($character->user)->get(route('waiting.show'))->assertOk()
            ->assertSee('Incapacitated!')
            ->assertDontSee('You are on the floor')
            ->assertSee('<div class="hazard-stripe" aria-hidden="true"></div>', false);
    }

    /** Somebody standing has no business here. */
    public function test_the_screen_turns_away_anybody_on_their_feet(): void
    {
        $character = $this->character();

        $this->actingAs($character->user)
            ->get(route('waiting.show'))
            ->assertRedirect(route('home'));
    }

    /** A cause that knows less shows less, rather than showing "unknown". */
    public function test_a_thin_cause_renders_what_it_has(): void
    {
        $character = $this->character();
        $character->forceFill(['current_synaptic_integrity' => 0])->save();
        $this->room()->put($character, new DownedCause(reason: 'readiness'));

        $this->actingAs($character->user)
            ->get(route('waiting.show'))
            ->assertOk()
            ->assertSee('deck stopped running', false)
            ->assertSee('carried nothing', false);
    }
}
