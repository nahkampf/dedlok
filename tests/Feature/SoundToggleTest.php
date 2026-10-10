<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Character;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The topbar speaker: saved on the account, drawn from it, and a bad value is refused. */
class SoundToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_defaults_on_and_is_saved(): void
    {
        $user = Character::factory()->create()->user;

        $this->assertTrue($user->fresh()->sound_enabled);

        $this->actingAs($user)->postJson(route('sound.update'), ['enabled' => false])->assertNoContent();
        $this->assertFalse($user->fresh()->sound_enabled);

        $this->actingAs($user)->get(route('inventory.index'))->assertOk()->assertSee('aria-pressed="false"', false);

        $this->actingAs($user)->postJson(route('sound.update'), ['enabled' => true])->assertNoContent();
        $this->assertTrue($user->fresh()->sound_enabled);
    }

    public function test_it_needs_a_value(): void
    {
        $user = Character::factory()->create()->user;

        $this->actingAs($user)->postJson(route('sound.update'), [])->assertStatus(422);
    }

    public function test_it_needs_a_sign_in(): void
    {
        $this->postJson(route('sound.update'), ['enabled' => true])->assertUnauthorized();
    }

    public function test_the_playlist_modal_is_in_the_page(): void
    {
        $user = Character::factory()->create()->user;

        $this->actingAs($user)->get(route('inventory.index'))
            ->assertSee('Official dedlok playlist')
            ->assertSee('list=PL5TIGn3RVGw9nM6sipiaF925G2enf6woK', false);
    }
}
