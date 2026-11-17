<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Character;
use App\Models\LeaderboardEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** The Artisan actions panel: admins only, listed commands only. */
final class ArtisanActionsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_admin' => true])->save();

        return $user;
    }

    public function test_the_page_lists_every_configured_command_with_its_description(): void
    {
        $html = $this->actingAs($this->admin())->get(route('admin.artisan.index'))->assertOk()->getContent();

        foreach ((array) config('artisan_actions') as $name) {
            $this->assertStringContainsString($name, $html);
        }
        $this->assertStringContainsString('Rank every character on every leaderboard', $html);
    }

    public function test_a_listed_command_runs_and_its_output_comes_back(): void
    {
        Character::factory()->create(['creds' => 500]);

        $this->actingAs($this->admin())->post(route('admin.artisan.run', 'leaderboard:snapshot'))
            ->assertRedirect()->assertSessionHas('artisan_output');

        $this->assertGreaterThan(0, LeaderboardEntry::query()->count());
    }

    public function test_a_command_that_is_not_listed_is_refused(): void
    {
        $this->actingAs($this->admin())->post(route('admin.artisan.run', 'migrate:fresh'))->assertNotFound();
        $this->actingAs($this->admin())->post(route('admin.artisan.run', 'admin:grant'))->assertNotFound();
    }

    public function test_a_player_cannot_see_or_run_anything(): void
    {
        $player = User::factory()->create();

        $this->actingAs($player)->get(route('admin.artisan.index'))->assertForbidden();
        $this->actingAs($player)->post(route('admin.artisan.run', 'leaderboard:snapshot'))->assertForbidden();
    }

    public function test_nothing_that_wants_an_argument_or_repeats_a_night_is_listed(): void
    {
        $listed = (array) config('artisan_actions');

        foreach (['bank:nightly', 'cyberspace:siphons', 'cyberspace:age-backdoors', 'characters:cool', 'standing:drift', 'drugs:metabolise', 'admin:grant', 'flag:set', 'fight:start'] as $never) {
            $this->assertNotContains($never, $listed);
        }

        foreach ($listed as $name) {
            $this->assertNotNull(Artisan::all()[$name] ?? null, "$name is not a command");
            $this->assertSame([], array_filter(Artisan::all()[$name]->getDefinition()->getArguments(), fn ($a) => $a->isRequired()));
        }
    }
}
