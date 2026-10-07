<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Character;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The Discord invitation: shown once, to a new account, and never again after it is closed. */
final class DiscordInviteTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_account_is_shown_it_before_it_has_a_character(): void
    {
        $user = User::factory()->create(['seen_discord_at' => null]);

        $this->actingAs($user)->get(route('character.create'))->assertOk()
            ->assertSee('Please join our Discord!')
            ->assertSee("Here's where to report bugs, give feedback, wish for features or just hang out and chill:")
            ->assertSee('href="https://discord.gg/WZuCf4YwER"', false)
            ->assertSee('data-show-on-load', false);
    }

    public function test_closing_it_is_remembered_and_it_is_not_shown_again(): void
    {
        $user = User::factory()->create(['seen_discord_at' => null]);

        // Reachable with no character: the invitation is the first thing a new account sees.
        $this->actingAs($user)->post(route('discord.seen'))->assertNoContent();
        $first = $user->fresh()->seen_discord_at;
        $this->assertNotNull($first);

        $this->actingAs($user->fresh())->get(route('character.create'))->assertOk()->assertDontSee('Please join our Discord!');

        // Idempotent: closing again does not move the moment.
        $this->travel(1)->hours();
        $this->actingAs($user->fresh())->post(route('discord.seen'))->assertNoContent();
        $this->assertEquals($first, $user->fresh()->seen_discord_at);
    }

    public function test_an_account_that_has_seen_it_is_not_shown_it(): void
    {
        $user = User::factory()->create();   // the factory's accounts have seen it
        Character::factory()->create(['user_id' => $user->id, 'archetype' => 3]);

        $this->actingAs($user)->get(route('character.index'))->assertOk()->assertDontSee('Please join our Discord!');
    }

    public function test_a_guest_is_never_shown_it_and_cannot_post_it(): void
    {
        $this->get(route('home'))->assertOk()->assertDontSee('Please join our Discord!');
        $this->post(route('discord.seen'))->assertRedirect();
    }
}
