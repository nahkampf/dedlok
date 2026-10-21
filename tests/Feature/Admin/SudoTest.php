<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Character;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SudoTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_plays_as_somebody_else_and_comes_back(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $target = Character::factory()->create();

        $this->actingAs($admin)->post(route('admin.sudo'), ['handle' => $target->handle])->assertRedirect('/');
        $this->assertAuthenticatedAs($target->user);

        $this->post(route('sudo.exit'))->assertRedirect(route('admin.index'));
        $this->assertAuthenticatedAs($admin);

        // Once back, there is nothing left to exit.
        $this->post(route('sudo.exit'))->assertForbidden();
    }

    public function test_only_an_admin_starts_one_and_only_a_real_character_is_played(): void
    {
        $target = Character::factory()->create();
        $plain = User::factory()->create();

        $this->actingAs($plain)->post(route('admin.sudo'), ['handle' => $target->handle])->assertForbidden();
        $this->post(route('sudo.exit'))->assertForbidden();

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->post(route('admin.sudo'), ['handle' => 'nobody-like-this'])->assertSessionHas('error');
        $this->assertAuthenticatedAs($admin);
    }
}
